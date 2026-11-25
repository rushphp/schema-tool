<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Driver;

use Rush\Tool\Schema\Diff\DiffOperation;
use Rush\Tool\Schema\Diff\SchemaDiff;
use Rush\Tool\Schema\Domain\Column;
use Rush\Tool\Schema\Domain\ForeignKey;
use Rush\Tool\Schema\Domain\Index;
use Rush\Tool\Schema\Domain\SchemaState;
use Rush\Tool\Schema\Domain\Table;
use Rush\Tool\Schema\Domain\View;


final class MysqlDialect implements SqlDialectInterface
{
    public function driver(): string
    {
        return 'mysql';
    }


    public function release(SchemaState $state): string
    {
        $statements = [];

        foreach ($this->orderedTables($state->tables) as $table) {
            $statements[] = $this->createTable($table);

            foreach ($table->indexes as $index) {
                $statements[] = $this->createIndex($table, $index);
            }
        }

        foreach ($state->views as $view) {
            $statements[] = $this->createView($view);
        }

        return $this->join($statements);
    }


    public function sql(SchemaDiff $diff): string
    {
        $statements = ['SET FOREIGN_KEY_CHECKS = 0'];
        $rebuilt = [];
        $created = [];

        foreach ($diff->operations as $operation) {
            if ($operation->type === DiffOperation::DROP_VIEW) {
                $statements[] = 'DROP VIEW IF EXISTS '. $this->name($this->string($operation->data, 'name'));
            }
        }

        foreach ($diff->operations as $operation) {
            if ($operation->type === DiffOperation::DROP_TABLE) {
                $statements[] = 'DROP TABLE IF EXISTS '. $this->name($this->string($operation->data, 'name'));
            }
        }

        foreach ($diff->operations as $operation) {
            if ($operation->type !== DiffOperation::REBUILD_TABLE) {
                continue;
            }

            $from = $operation->data['from'] ?? null;
            $to = $operation->data['to'] ?? null;

            if ( ! $from instanceof Table || ! $to instanceof Table) {
                continue;
            }

            $rebuilt[] = $to;

            foreach ($this->rebuildTable($from, $to) as $statement) {
                $statements[] = $statement;
            }
        }

        foreach ($diff->operations as $operation) {
            if ($operation->type === DiffOperation::CREATE_TABLE) {
                $table = $operation->data['table'] ?? null;

                if ($table instanceof Table) {
                    $created[] = $table;
                }
            }
        }

        foreach ($this->orderedTables($this->keyed($created)) as $table) {
            $statements[] = $this->createTable($table);
        }

        foreach ([...$rebuilt, ...$created] as $table) {
            foreach ($table->indexes as $index) {
                $statements[] = $this->createIndex($table, $index);
            }
        }

        foreach ($diff->operations as $operation) {
            if ($operation->type !== DiffOperation::CREATE_VIEW) {
                continue;
            }

            $view = $operation->data['view'] ?? null;

            if ($view instanceof View) {
                $statements[] = $this->createView($view);
            }
        }

        $statements[] = 'SET FOREIGN_KEY_CHECKS = 1';

        return $this->join($statements);
    }


    /** @return list<string> */
    private function rebuildTable(Table $from, Table $to): array
    {
        $temporary = '__rush_new_'. $to->name;
        $common = [];

        foreach ($from->orderedColumns() as $column) {
            if (isset($to->columns[$column->name])) {
                $common[] = $column->name;
            }
        }

        $statements = [
            'DROP TABLE IF EXISTS '. $this->name($temporary),
            $this->createTableAs($to, $temporary),
        ];

        if ($common !== []) {
            $columns = implode(', ', array_map($this->name(...), $common));
            $statements[] = 'INSERT INTO '. $this->name($temporary)
                .' ('. $columns .') SELECT '. $columns .' FROM '. $this->name($to->name);
        }

        $statements[] = 'DROP TABLE '. $this->name($to->name);
        $statements[] = 'RENAME TABLE '. $this->name($temporary) .' TO '. $this->name($to->name);

        return $statements;
    }


    private function createTable(Table $table): string
    {
        return $this->createTableAs($table, $table->name);
    }


    private function createTableAs(Table $table, string $name): string
    {
        $lines = [];

        foreach ($table->orderedColumns() as $column) {
            $lines[] = '  '. $this->column($column);
        }

        if ($table->primaryKey !== []) {
            $lines[] = '  PRIMARY KEY ('. $this->columns($table->primaryKey) .')';
        }

        foreach ($table->foreignKeys as $foreignKey) {
            $lines[] = '  '. $this->foreignKey($foreignKey);
        }

        foreach ($table->checks as $check) {
            $lines[] = '  CONSTRAINT '. $this->name($check->name) .' CHECK '. $this->checkExpression($check->expression);
        }

        $engine = $table->options['engine'] ?? 'InnoDB';
        $collation = $table->options['collation'] ?? null;
        $options = 'ENGINE='. (is_string($engine) ? $engine : 'InnoDB');

        if (is_string($collation) && $collation !== '') {
            $options .= ' COLLATE='. $collation;
        }

        return 'CREATE TABLE '. $this->name($name) ." (\n"
            . implode(",\n", $lines)
            ."\n) ". $options;
    }


    private function column(Column $column): string
    {
        $definition = $this->name($column->name) .' '. $column->type;

        if ( ! $column->nullable) {
            $definition .= ' NOT NULL';
        } else {
            $definition .= ' NULL';
        }

        if ($column->default !== null) {
            $definition .= ' DEFAULT '. ($column->defaultExpression ? '('. $column->default .')' : $column->default);
        }

        if ($column->onUpdate !== null) {
            $definition .= ' ON UPDATE '. $column->onUpdate;
        }

        if ($column->autoIncrement) {
            $definition .= ' AUTO_INCREMENT';
        }

        return $definition;
    }


    private function foreignKey(ForeignKey $foreignKey): string
    {
        $sql = 'FOREIGN KEY ('. $this->columns($foreignKey->columns) .') REFERENCES '
            . $this->name($foreignKey->referencedTable)
            .' ('. $this->columns($foreignKey->referencedColumns) .')';

        if ($foreignKey->onDelete !== '' && strtoupper($foreignKey->onDelete) !== 'RESTRICT') {
            $sql .= ' ON DELETE '. $foreignKey->onDelete;
        }

        if ($foreignKey->onUpdate !== '' && strtoupper($foreignKey->onUpdate) !== 'RESTRICT') {
            $sql .= ' ON UPDATE '. $foreignKey->onUpdate;
        }

        return $sql;
    }


    private function createIndex(Table $table, Index $index): string
    {
        $unique = $index->unique ? 'UNIQUE ' : '';

        return 'CREATE '. $unique .'INDEX '. $this->name($index->name)
            .' ON '. $this->name($table->name)
            .' ('. $this->indexColumns($index) .')';
    }


    private function createView(View $view): string
    {
        return rtrim($view->sql, ';');
    }


    /**
     * @param array<string, Table> $tables
     * @return list<Table>
     */
    private function orderedTables(array $tables): array
    {
        $remaining = $tables;
        $ordered = [];

        while ($remaining !== []) {
            $progress = false;

            foreach ($remaining as $name => $table) {
                $ready = true;

                foreach ($table->foreignKeys as $foreignKey) {
                    $referenced = $foreignKey->referencedTable;

                    if ($referenced !== $name && isset($remaining[$referenced])) {
                        $ready = false;
                        break;
                    }
                }

                if ( ! $ready) {
                    continue;
                }

                $ordered[] = $table;
                unset($remaining[$name]);
                $progress = true;
            }

            if ( ! $progress) {
                ksort($remaining, SORT_STRING);

                foreach ($remaining as $table) {
                    $ordered[] = $table;
                }

                break;
            }
        }

        return $ordered;
    }


    /**
     * @param list<Table> $tables
     * @return array<string, Table>
     */
    private function keyed(array $tables): array
    {
        $keyed = [];

        foreach ($tables as $table) {
            $keyed[$table->name] = $table;
        }

        return $keyed;
    }


    /** @param list<string> $columns */
    private function columns(array $columns): string
    {
        return implode(', ', array_map($this->name(...), $columns));
    }


    private function indexColumns(Index $index): string
    {
        $parts = [];

        foreach ($index->columns as $position => $column) {
            $part = $this->name($column);
            $length = $index->lengths[$position] ?? null;

            if (is_int($length) && $length > 0) {
                $part .= '('. $length .')';
            }

            if (($index->directions[$position] ?? 'ASC') === 'DESC') {
                $part .= ' DESC';
            }

            $parts[] = $part;
        }

        return implode(', ', $parts);
    }


    private function checkExpression(string $expression): string
    {
        $expression = trim($expression);

        if ($expression === '') {
            return '(1)';
        }

        if (str_starts_with($expression, '(')) {
            return $expression;
        }

        return '('. $expression .')';
    }


    private function name(string $name): string
    {
        return '`'. str_replace('`', '``', $name) .'`';
    }


    /** @param list<string> $statements */
    private function join(array $statements): string
    {
        if ($statements === []) {
            return '';
        }

        return implode(";\n\n", $statements) .";\n";
    }


    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
