<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Canonical;

use Rush\Tool\Schema\Domain\SchemaState;


final class SchemaHasher
{
    public function hash(SchemaState $state): string
    {
        return $this->hashArray($state->toArray());
    }


    /** @param array<string, mixed> $data */
    public function hashArray(array $data): string
    {
        return 'sha256:'. hash('sha256', CanonicalJson::encode($data));
    }


    public static function hashSql(string $sql): string
    {
        return 'sha256:'. hash('sha256', $sql);
    }
}
