<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Driver;

use Rush\Tool\Schema\Exception\DriverException;


final class DriverFactory
{
    public static function make(string $driver): DriverInterface
    {
        return match ($driver) {
            'sqlite' => new SqliteDriver(),
            'mysql' => new MysqlDriver(),
            default => throw new DriverException("schema: driver '{$driver}' is not implemented"),
        };
    }
}
