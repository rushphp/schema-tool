<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Trace;


final readonly class Checkpoint
{
    /**
     * @param array<string, string> $artifacts
     */
    public function __construct(
        public string $id,
        public string $save,
        public ?string $label,
        public ?string $baseCheckpoint,
        public string $schemaHash,
        public string $createdAt,
        public string $verifiedAt,
        public array $artifacts,
    ) {
    }


    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $artifacts = [];

        foreach (is_array($data['artifacts'] ?? null) ? $data['artifacts'] : [] as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $artifacts[$key] = $value;
            }
        }

        return new self(
            self::string($data, 'id'),
            self::string($data, 'save'),
            self::nullableString($data, 'label'),
            self::nullableString($data, 'baseCheckpoint'),
            self::string($data, 'schemaHash'),
            self::string($data, 'createdAt'),
            self::string($data, 'verifiedAt'),
            $artifacts,
        );
    }


    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format' => 1,
            'id' => $this->id,
            'save' => $this->save,
            'label' => $this->label,
            'baseCheckpoint' => $this->baseCheckpoint,
            'schemaHash' => $this->schemaHash,
            'createdAt' => $this->createdAt,
            'verifiedAt' => $this->verifiedAt,
            'artifacts' => $this->artifacts === [] ? new \stdClass() : $this->artifacts,
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
