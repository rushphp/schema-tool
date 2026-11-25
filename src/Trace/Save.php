<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Trace;


final readonly class Save
{
    public function __construct(
        public string $id,
        public ?string $parent,
        public ?string $label,
        public string $createdAt,
        public ?string $fromHash,
        public string $toHash,
        public string $upHash,
        public string $downHash,
        public bool $destructive,
    ) {
    }


    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            self::string($data, 'id'),
            self::nullableString($data, 'parent'),
            self::nullableString($data, 'label'),
            self::string($data, 'createdAt'),
            self::nullableString($data, 'fromHash'),
            self::string($data, 'toHash'),
            self::string($data, 'upHash'),
            self::string($data, 'downHash'),
            ($data['destructive'] ?? false) === true,
        );
    }


    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format' => 1,
            'id' => $this->id,
            'parent' => $this->parent,
            'label' => $this->label,
            'createdAt' => $this->createdAt,
            'fromHash' => $this->fromHash,
            'toHash' => $this->toHash,
            'upHash' => $this->upHash,
            'downHash' => $this->downHash,
            'destructive' => $this->destructive,
        ];
    }


    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }


    /** @param array<string, mixed> $data */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
