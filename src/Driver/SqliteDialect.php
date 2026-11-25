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


final class SqliteDialect implements SqlDialectInterface
{
    public function driver(): string
    {
        return 'sqlite';
    }


    public function release(SchemaState $state): string
    {
        $statements = [];

        foreach ($this->orderedTables($state->tables) as $table) {
            $statements[] = $this->createTable($table);

            foreach ($table->indexes as $index) {
                if ($index->source === 'explicit') {
                    $statements[] = $this->createIndex($table, $index);
                }
            }
        }

        foreach ($state->views as $view) {
            $statements[] = $this->createView($view);
        }

        return $this->join($statements);
    }


    public function sql(SchemaDiff $diff): string
    {
        $statements = [];
        $rebuilt = [];
        $created = [];

        foreach ($diff->operations as $operation) {
            if ($operation->type === DiffOperation::DROP_VIEW) {
                $name = $this->string($operation->data, 'name');
                $statements[] = 'DROP VIEW IF EXISTS '. $this->name($name);
            }
        }

        foreach ($diff->operations as $operation) {
            if ($operation->type === DiffOperation::DROP_INDEX) {
                $name = $this->string($operation->data, 'name');
                $statements[] = 'DROP INDEX IF EXISTS '. $this->name($name);
            }
        }

        foreach ($diff->operations as $operation) {
            if ($operation->type === DiffOperation::DROP_TABLE) {
                $name = $this->string($operation->data, 'name');
                $statements[] = 'DROP TABLE IF EXISTS '. $this->name($name);
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
                if ($index->source === 'explicit') {
                    $statements[] = $this->createIndex($table, $index);
                }
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
        $statements[] = 'ALTER TABLE '. $this->name($temporary) .' RENAME TO '. $this->name($to->name);

        return $statements;
    }


    private function createTable(Table $table): string
    {
        return $this->createTableAs($table, $table->name);
    }


    private function createTableAs(Table $table, string $name): string
    {
        $lines = [];
        $autoIncrement = false;

        foreach ($table->orderedColumns() as $column) {
            $autoIncrement = $autoIncrement || $column->autoIncrement;
        }

        foreach ($table->orderedColumns() as $column) {
            $lines[] = '    '. $this->column($column);
        }

        if ( ! $autoIncrement && $table->primaryKey !== []) {
            $lines[] = '    PRIMARY KEY ('. $this->columns($table->primaryKey) .')';
        }

        foreach ($table->indexes as $index) {
            if ($index->source !== 'constraint' || $index->columns === []) {
                continue;
            }

            $lines[] = '    UNIQUE ('. $this->columns($index->columns) .')';
        }

        foreach ($table->foreignKeys as $foreignKey) {
            $lines[] = '    '. $this->foreignKey($foreignKey);
        }

        return 'CREATE TABLE '. $this->name($name) ." (\n"
            . implode(",\n", $lines)
            ."\n)";
    }


    private function column(Column $column): string
    {
        $definition = $this->name($column->name);

        if (trim($column->type) !== '') {
            $definition .= ' '. trim($column->type);
        }

        if ($column->autoIncrement) {
            return $definition .' PRIMARY KEY AUTOINCREMENT';
        }

        if ( ! $column->nullable) {
            $definition .= ' NOT NULL';
        }

        if ($column->default !== null) {
            $definition .= ' DEFAULT '. $column->default;
        }

        return $definition;
    }


    private function foreignKey(ForeignKey $foreignKey): string
    {
        $sql = 'FOREIGN KEY ('. $this->columns($foreignKey->columns) .') REFERENCES '
            . $this->name($foreignKey->referencedTable);

        if ($foreignKey->referencedColumns !== []) {
            $sql .= ' ('. $this->columns($foreignKey->referencedColumns) .')';
        }

        if ($foreignKey->onDelete !== '') {
            $sql .= ' ON DELETE '. $foreignKey->onDelete;
        }

        if ($foreignKey->onUpdate !== '') {
            $sql .= ' ON UPDATE '. $foreignKey->onUpdate;
        }

        if ($foreignKey->match !== '' && strtoupper($foreignKey->match) !== 'NONE') {
            $sql .= ' MATCH '. $foreignKey->match;
        }

        return $sql;
    }


    private function createIndex(Table $table, Index $index): string
    {
        if ($index->sql !== null && trim($index->sql) !== '') {
            return rtrim($index->sql, ';');
        }

        $unique = $index->unique ? 'UNIQUE ' : '';

        return 'CREATE '. $unique .'INDEX '. $this->name($index->name)
            .' ON '. $this->name($table->name)
            .' ('. $this->columns($index->columns) .')';
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


    private function name(string $name): string
    {
        return '"'. str_replace('"', '""', $name) .'"';
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
