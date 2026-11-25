<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Domain;


final class Index
{
    /**
     * @param list<string> $columns
     * @param list<int|null> $lengths
     * @param list<string> $directions
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly bool $unique,
        public readonly string $source,
        public readonly bool $partial,
        public readonly ?string $sql = null,
        public readonly array $lengths = [],
        public readonly array $directions = [],
    ) {
    }


    /** @param array<string, mixed> $data */
    public static function fromArray(string $key, array $data): self
    {
        $columns = [];
        $rawLengths = is_array($data['lengths'] ?? null) ? $data['lengths'] : [];
        $rawDirections = is_array($data['directions'] ?? null) ? $data['directions'] : [];
        $hasLengths = $rawLengths !== [];
        $hasDirections = $rawDirections !== [];
        $lengths = [];
        $directions = [];

        foreach (is_array($data['columns'] ?? null) ? $data['columns'] : [] as $position => $column) {
            if ( ! is_string($column)) {
                continue;
            }

            $columns[] = $column;

            if ($hasLengths) {
                $length = $rawLengths[$position] ?? null;
                $lengths[] = is_int($length) ? $length : null;
            }

            if ($hasDirections) {
                $direction = $rawDirections[$position] ?? 'ASC';
                $directions[] = is_string($direction) ? strtoupper($direction) : 'ASC';
            }
        }

        return new self(
            is_string($data['name'] ?? null) ? $data['name'] : $key,
            $columns,
            (bool) ($data['unique'] ?? false),
            is_string($data['source'] ?? null) ? $data['source'] : 'explicit',
            (bool) ($data['partial'] ?? false),
            is_string($data['sql'] ?? null) ? $data['sql'] : null,
            $lengths,
            $directions,
        );
    }


    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'columns' => $this->columns,
            'unique' => $this->unique,
            'source' => $this->source,
            'partial' => $this->partial,
            'sql' => $this->sql,
            'lengths' => $this->lengths,
            'directions' => $this->directions,
        ];
    }
}
