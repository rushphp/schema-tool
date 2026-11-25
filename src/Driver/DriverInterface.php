<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Driver;

use PDO;
use Rush\Tool\Schema\Domain\SchemaState;


interface DriverInterface
{
    public function driver(): string;

    public function version(PDO $pdo): string;

    /** @return list<string> */
    public function unsupportedObjects(PDO $pdo): array;

    public function inspect(PDO $pdo): SchemaState;

    public function dialect(): SqlDialectInterface;
}
