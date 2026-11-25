<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Domain;


final class Column
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $nullable,
        public readonly ?string $default,
        public readonly int $position,
        public readonly bool $autoIncrement,
        public readonly ?string $onUpdate = null,
        public readonly bool $defaultExpression = false,
    ) {
    }


    /** @param array<string, mixed> $data */
    public static function fromArray(string $name, array $data): self
    {
        $onUpdate = $data['onUpdate'] ?? null;

        return new self(
            $name,
            is_string($data['type'] ?? null) ? $data['type'] : '',
            (bool) ($data['nullable'] ?? true),
            is_string($data['default'] ?? null) ? $data['default'] : null,
            is_int($data['position'] ?? null) ? $data['position'] : 0,
            (bool) ($data['autoIncrement'] ?? false),
            is_string($onUpdate) && $onUpdate !== '' ? $onUpdate : null,
            (bool) ($data['defaultExpression'] ?? false),
        );
    }


    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'nullable' => $this->nullable,
            'default' => $this->default,
            'position' => $this->position,
            'autoIncrement' => $this->autoIncrement,
            'onUpdate' => $this->onUpdate,
            'defaultExpression' => $this->defaultExpression,
        ];
    }
}
