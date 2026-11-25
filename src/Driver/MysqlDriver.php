<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Driver;

use PDO;
use Rush\Tool\Schema\Domain\Column;
use Rush\Tool\Schema\Domain\ForeignKey;
use Rush\Tool\Schema\Domain\Index;
use Rush\Tool\Schema\Domain\SchemaState;
use Rush\Tool\Schema\Domain\Table;
use Rush\Tool\Schema\Domain\View;
use Rush\Tool\Schema\Exception\DriverException;


final class MysqlDriver implements DriverInterface
{
    public function driver(): string
    {
        return 'mysql';
    }


    public function version(PDO $pdo): string
    {
        $statement = $pdo->query('SELECT VERSION()');

        if ($statement === false) {
            return '';
        }

        $version = $statement->fetchColumn();

        return is_string($version) ? $version : '';
    }


    /** @return list<string> */
    public function unsupportedObjects(PDO $pdo): array
    {
        $objects = [];

        $queries = [
            'trigger' => "SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()",
            'procedure' => "SELECT ROUTINE_NAME AS name FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = 'PROCEDURE'",
            'function' => "SELECT ROUTINE_NAME AS name FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = 'FUNCTION'",
            'event' => "SELECT EVENT_NAME AS name FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()",
            'generated column' => "SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND EXTRA LIKE '%GENERATED%'",
            'check constraint' => "SELECT CONSTRAINT_NAME AS name FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()",
            'expression index' => "SELECT DISTINCT INDEX_NAME AS name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND EXPRESSION IS NOT NULL",
            'fulltext index' => "SELECT DISTINCT INDEX_NAME AS name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_TYPE = 'FULLTEXT'",
            'spatial index' => "SELECT DISTINCT INDEX_NAME AS name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_TYPE = 'SPATIAL'",
        ];

        foreach ($queries as $type => $sql) {
            try {
                $statement = $pdo->query($sql);
            } catch (\PDOException) {
                continue;
            }

            if ($statement === false) {
                continue;
            }

            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $name = $row['name'] ?? null;

                if (is_string($name) && $name !== '') {
                    $objects[] = $type .' '. $name;
                }
            }
        }

        sort($objects, SORT_STRING);

        return array_values(array_unique($objects));
    }


    public function inspect(PDO $pdo): SchemaState
    {
        $tables = [];
        $views = [];

        $objects = $this->rows(
            $pdo,
            "SELECT TABLE_NAME AS name, TABLE_TYPE AS type, ENGINE AS engine, TABLE_COLLATION AS collation
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE IN ('BASE TABLE', 'VIEW')
             ORDER BY TABLE_NAME",
        );

        $viewDefinitions = [];

        foreach ($this->rows(
            $pdo,
            'SELECT TABLE_NAME AS name, VIEW_DEFINITION AS definition FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()',
        ) as $row) {
            $name = $this->string($row, 'name');
            $definition = $this->string($row, 'definition');

            if ($name !== '' && $definition !== '') {
                $viewDefinitions[$name] = $definition;
            }
        }

        foreach ($objects as $object) {
            $name = $this->string($object, 'name');
            $type = $this->string($object, 'type');

            if ($name === '') {
                continue;
            }

            if ($type === 'VIEW') {
                $views[$name] = new View($name, 'CREATE VIEW '. $this->name($name) .' AS '. ($viewDefinitions[$name] ?? ''));
                continue;
            }

            $options = [];

            foreach (['engine', 'collation'] as $key) {
                $value = $this->string($object, $key);

                if ($value !== '') {
                    $options[$key] = $value;
                }
            }

            $tables[$name] = $this->table($pdo, $name, $options);
        }

        ksort($tables, SORT_STRING);
        ksort($views, SORT_STRING);

        return new SchemaState($tables, $views);
    }


    public function dialect(): SqlDialectInterface
    {
        return new MysqlDialect();
    }


    /**
     * @param array<string, mixed> $options
     */
    private function table(PDO $pdo, string $name, array $options): Table
    {
        $columns = [];
        $primaryKey = [];

        foreach ($this->rows(
            $pdo,
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS default_value,
                    ORDINAL_POSITION AS position, EXTRA AS extra, COLUMN_KEY AS column_key
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION',
            [$name],
        ) as $row) {
            $columnName = $this->string($row, 'name');

            if ($columnName === '') {
                continue;
            }

            $type = $this->string($row, 'type');
            $extra = strtolower($this->string($row, 'extra'));
            $columns[$columnName] = new Column(
                $columnName,
                $type,
                strtoupper($this->string($row, 'nullable')) === 'YES',
                $this->defaultValue($row, $type, $extra),
                $this->int($row, 'position'),
                str_contains($extra, 'auto_increment'),
            );

            if (strtoupper($this->string($row, 'column_key')) === 'PRI') {
                $primaryKey[] = $columnName;
            }
        }

        $foreignKeyNames = $this->foreignKeyNames($pdo, $name);

        return new Table(
            $name,
            $columns,
            $primaryKey,
            $this->indexes($pdo, $name, $foreignKeyNames),
            $this->foreignKeys($pdo, $name),
            $options,
        );
    }


    /**
     * @param list<string> $foreignKeyNames
     * @return array<string, Index>
     */
    private function indexes(PDO $pdo, string $table, array $foreignKeyNames): array
    {
        $grouped = [];

        foreach ($this->rows(
            $pdo,
            'SELECT INDEX_NAME AS name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS seq, COLUMN_NAME AS column_name
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$table],
        ) as $row) {
            $name = $this->string($row, 'name');

            if ($name === '' || $name === 'PRIMARY' || in_array($name, $foreignKeyNames, true)) {
                continue;
            }

            $grouped[$name][] = $row;
        }

        $indexes = [];

        foreach ($grouped as $name => $rows) {
            $columns = [];

            foreach ($rows as $row) {
                $column = $this->string($row, 'column_name');

                if ($column !== '') {
                    $columns[] = $column;
                }
            }

            $first = $rows[0];
            $indexes[$name] = new Index(
                $name,
                $columns,
                $this->int($first, 'non_unique') === 0,
                'explicit',
                false,
                null,
            );
        }

        ksort($indexes, SORT_STRING);

        return $indexes;
    }


    /** @return list<string> */
    private function foreignKeyNames(PDO $pdo, string $table): array
    {
        $names = [];

        foreach ($this->rows(
            $pdo,
            'SELECT DISTINCT CONSTRAINT_NAME AS name FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table],
        ) as $row) {
            $name = $this->string($row, 'name');

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }


    /** @return array<string, ForeignKey> */
    private function foreignKeys(PDO $pdo, string $table): array
    {
        $grouped = [];

        foreach ($this->rows(
            $pdo,
            'SELECT k.CONSTRAINT_NAME AS name, k.COLUMN_NAME AS column_name, k.ORDINAL_POSITION AS position,
                    k.REFERENCED_TABLE_NAME AS referenced_table, k.REFERENCED_COLUMN_NAME AS referenced_column,
                    r.UPDATE_RULE AS update_rule, r.DELETE_RULE AS delete_rule
             FROM information_schema.KEY_COLUMN_USAGE k
             INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                 ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
            [$table],
        ) as $row) {
            $name = $this->string($row, 'name');
            $grouped[$name][] = $row;
        }

        $foreignKeys = [];

        foreach ($grouped as $name => $rows) {
            $columns = [];
            $referencedColumns = [];

            foreach ($rows as $row) {
                $columns[] = $this->string($row, 'column_name');
                $referencedColumns[] = $this->string($row, 'referenced_column');
            }

            $first = $rows[0];
            $referencedTable = $this->string($first, 'referenced_table');
            $key = 'fk:'. implode(',', $columns) .'->'. $referencedTable .'('. implode(',', $referencedColumns) .')';

            if (isset($foreignKeys[$key])) {
                $key .= '#'. $name;
            }

            $foreignKeys[$key] = new ForeignKey(
                $key,
                $columns,
                $referencedTable,
                $referencedColumns,
                $this->string($first, 'delete_rule'),
                $this->string($first, 'update_rule'),
                '',
            );
        }

        ksort($foreignKeys, SORT_STRING);

        return $foreignKeys;
    }


    /**
     * @param array<string, mixed> $row
     */
    private function defaultValue(array $row, string $type, string $extra): ?string
    {
        $value = $row['default_value'] ?? null;

        if ($value === null) {
            return null;
        }

        if ( ! is_scalar($value)) {
            return null;
        }

        $default = (string) $value;
        $upper = strtoupper($default);

        if (str_contains($extra, 'default_generated')
            || str_starts_with($default, '(')
            || in_array($upper, ['CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP()', 'CURRENT_DATE', 'CURRENT_TIME', 'NULL'], true)
            || preg_match('/\A(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real|bit|year)/i', $type) === 1
            || str_starts_with($default, "'")
            || str_starts_with($default, '"')
        ) {
            return $default;
        }

        return "'". str_replace("'", "''", $default) ."'";
    }


    /**
     * @param list<mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function rows(PDO $pdo, string $sql, array $parameters = []): array
    {
        $statement = $pdo->prepare($sql);

        if ($statement === false) {
            throw new DriverException('schema: could not prepare mysql metadata query');
        }

        $statement->execute($parameters);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }


    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_string($value) ? $value : '';
    }


    /** @param array<string, mixed> $row */
    private function int(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }


    private function name(string $name): string
    {
        return '`'. str_replace('`', '``', $name) .'`';
    }
}
