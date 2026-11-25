<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Domain;

use stdClass;


final class Table
{
    /**
     * @param array<string, Column> $columns
     * @param list<string> $primaryKey
     * @param array<string, Index> $indexes
     * @param array<string, ForeignKey> $foreignKeys
     * @param array<string, mixed> $options
     * @param array<string, Check> $checks
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly array $primaryKey,
        public readonly array $indexes,
        public readonly array $foreignKeys,
        public readonly array $options = [],
        public readonly array $checks = [],
    ) {
    }


    /** @param array<string, mixed> $data */
    public static function fromArray(string $name, array $data): self
    {
        $columns = [];

        foreach (self::map($data['columns'] ?? null) as $columnName => $column) {
            if (is_string($columnName) && is_array($column)) {
                /** @var array<string, mixed> $column */
                $columns[$columnName] = Column::fromArray($columnName, $column);
            }
        }

        $indexes = [];

        foreach (self::map($data['indexes'] ?? null) as $key => $index) {
            if (is_string($key) && is_array($index)) {
                /** @var array<string, mixed> $index */
                $indexes[$key] = Index::fromArray($key, $index);
            }
        }

        $foreignKeys = [];

        foreach (self::map($data['foreignKeys'] ?? null) as $key => $foreignKey) {
            if (is_string($key) && is_array($foreignKey)) {
                /** @var array<string, mixed> $foreignKey */
                $foreignKeys[$key] = ForeignKey::fromArray($key, $foreignKey);
            }
        }

        $checks = [];

        foreach (self::map($data['checks'] ?? null) as $key => $check) {
            if (is_string($key) && is_array($check)) {
                /** @var array<string, mixed> $check */
                $checks[$key] = Check::fromArray($key, $check);
            }
        }

        return new self(
            $name,
            $columns,
            self::strings($data['primaryKey'] ?? null),
            $indexes,
            $foreignKeys,
            self::map($data['options'] ?? null),
            $checks,
        );
    }


    /** @return list<Column> */
    public function orderedColumns(): array
    {
        $columns = array_values($this->columns);

        usort(
            $columns,
            static fn (Column $left, Column $right): int => $left->position <=> $right->position,
        );

        return $columns;
    }


    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $columns = [];

        foreach ($this->columns as $name => $column) {
            $columns[$name] = $column->toArray();
        }

        $indexes = [];

        foreach ($this->indexes as $name => $index) {
            $indexes[$name] = $index->toArray();
        }

        $foreignKeys = [];

        foreach ($this->foreignKeys as $name => $foreignKey) {
            $foreignKeys[$name] = $foreignKey->toArray();
        }

        $checks = [];

        foreach ($this->checks as $name => $check) {
            $checks[$name] = $check->toArray();
        }

        return [
            'columns' => $columns === [] ? new stdClass() : $columns,
            'primaryKey' => $this->primaryKey,
            'indexes' => $indexes === [] ? new stdClass() : $indexes,
            'foreignKeys' => $foreignKeys === [] ? new stdClass() : $foreignKeys,
            'options' => $this->options === [] ? new stdClass() : $this->options,
            'checks' => $checks === [] ? new stdClass() : $checks,
        ];
    }


    /** @return array<array-key, mixed> */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }


    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        $items = [];

        foreach (self::map($value) as $item) {
            if (is_string($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
