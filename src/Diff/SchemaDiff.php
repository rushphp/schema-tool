<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Diff;

use Rush\Tool\Schema\Canonical\CanonicalJson;
use Rush\Tool\Schema\Domain\Column;
use Rush\Tool\Schema\Domain\Index;
use Rush\Tool\Schema\Domain\SchemaState;
use Rush\Tool\Schema\Domain\Table;
use Rush\Tool\Schema\Domain\View;


final readonly class SchemaDiff
{
    /**
     * @param list<DiffOperation> $operations
     */
    public function __construct(
        public array $operations,
        public bool $destructive,
    ) {
    }


    public static function between(SchemaState $from, SchemaState $to): self
    {
        $operations = [];
        $destructive = false;

        $fromTables = $from->tables;
        $toTables = $to->tables;

        foreach ($fromTables as $name => $table) {
            if (isset($toTables[$name])) {
                continue;
            }

            $operations[] = new DiffOperation(DiffOperation::DROP_TABLE, ['name' => $name]);
            $destructive = true;

            foreach ($table->indexes as $index) {
                if ($index->source === 'explicit') {
                    $operations[] = new DiffOperation(DiffOperation::DROP_INDEX, ['name' => $index->name]);
                }
            }
        }

        foreach ($toTables as $name => $table) {
            if (isset($fromTables[$name])) {
                continue;
            }

            $operations[] = new DiffOperation(DiffOperation::CREATE_TABLE, ['table' => $table]);
        }

        foreach ($toTables as $name => $table) {
            $old = $fromTables[$name] ?? null;

            if ($old === null) {
                continue;
            }

            if (self::same($old->toArray(), $table->toArray())) {
                continue;
            }

            $operations[] = new DiffOperation(DiffOperation::REBUILD_TABLE, ['from' => $old, 'to' => $table]);

            if (self::losesData($old, $table)) {
                $destructive = true;
            }
        }

        foreach ($fromTables as $name => $table) {
            $new = $toTables[$name] ?? null;

            if ($new === null || ! self::same($table->toArray(), $new->toArray())) {
                continue;
            }

            foreach ($table->indexes as $key => $index) {
                if ($index->source !== 'explicit') {
                    continue;
                }

                $replacement = $new->indexes[$key] ?? null;

                if ($replacement === null) {
                    $operations[] = new DiffOperation(DiffOperation::DROP_INDEX, ['name' => $index->name]);
                    continue;
                }

                if ( ! self::same($index->toArray(), $replacement->toArray())) {
                    $operations[] = new DiffOperation(DiffOperation::DROP_INDEX, ['name' => $index->name]);
                    $operations[] = new DiffOperation(DiffOperation::CREATE_INDEX, ['table' => $name, 'index' => $replacement]);
                }
            }

            foreach ($new->indexes as $key => $index) {
                if ($index->source !== 'explicit' || isset($table->indexes[$key])) {
                    continue;
                }

                $operations[] = new DiffOperation(DiffOperation::CREATE_INDEX, ['table' => $name, 'index' => $index]);
            }
        }

        foreach ($from->views as $name => $view) {
            if ( ! isset($to->views[$name])) {
                $operations[] = new DiffOperation(DiffOperation::DROP_VIEW, ['name' => $name]);
            }
        }

        foreach ($to->views as $name => $view) {
            $old = $from->views[$name] ?? null;

            if ($old !== null && self::same($old->toArray(), $view->toArray())) {
                continue;
            }

            if ($old !== null) {
                $operations[] = new DiffOperation(DiffOperation::DROP_VIEW, ['name' => $name]);
            }

            $operations[] = new DiffOperation(DiffOperation::CREATE_VIEW, ['view' => $view]);
        }

        return new self($operations, $destructive);
    }


    public function isEmpty(): bool
    {
        return $this->operations === [];
    }


    /** @return array<string, int> */
    public function summary(): array
    {
        $summary = [
            'create_table' => 0,
            'drop_table' => 0,
            'rebuild_table' => 0,
            'create_index' => 0,
            'drop_index' => 0,
            'create_view' => 0,
            'drop_view' => 0,
        ];

        foreach ($this->operations as $operation) {
            $summary[$operation->type] = ($summary[$operation->type] ?? 0) + 1;
        }

        return $summary;
    }


    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private static function same(array $left, array $right): bool
    {
        return CanonicalJson::encode($left) === CanonicalJson::encode($right);
    }


    private static function losesData(Table $from, Table $to): bool
    {
        foreach ($from->columns as $name => $column) {
            $replacement = $to->columns[$name] ?? null;

            if ($replacement === null) {
                return true;
            }

            if (self::columnLosesData($column, $replacement)) {
                return true;
            }
        }

        return false;
    }


    private static function columnLosesData(Column $from, Column $to): bool
    {
        if (strcasecmp(trim($from->type), trim($to->type)) !== 0) {
            return true;
        }

        return $from->nullable && ! $to->nullable;
    }


    public function __toString(): string
    {
        return CanonicalJson::encode(array_map(
            static fn (DiffOperation $operation): array => [
                'type' => $operation->type,
                'name' => $operation->data['name'] ?? null,
            ],
            $this->operations,
        ));
    }
}
