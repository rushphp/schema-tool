<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Domain;


final class Check
{
    public function __construct(
        public readonly string $name,
        public readonly string $expression,
    ) {
    }


    /** @param array<string, mixed> $data */
    public static function fromArray(string $key, array $data): self
    {
        return new self(
            is_string($data['name'] ?? null) ? $data['name'] : $key,
            is_string($data['expression'] ?? null) ? $data['expression'] : '',
        );
    }


    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'expression' => $this->expression,
        ];
    }
}
