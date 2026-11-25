<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Session;

use Rush\Tool\Schema\Domain\SchemaState;


interface SessionInterface
{
    public function inspect(): SchemaState;

    public function version(): string;

    public function flavor(): string;

    /** @return array<string, bool> */
    public function capabilities(): array;

    /** @return list<string> */
    public function unsupportedObjects(): array;

    public function apply(string $sql): void;

    public function backup(): string;
}
