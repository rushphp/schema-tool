<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Session;

use PDO;
use Rush\Tool\Schema\Backup\BackupService;
use Rush\Tool\Schema\Config\DatabaseConfig;
use Rush\Tool\Schema\Config\TargetConfig;
use Rush\Tool\Schema\Domain\SchemaState;
use Rush\Tool\Schema\Driver\DriverInterface;
use Rush\Tool\Schema\Sql\SqlExecutor;


final class LocalSession implements SessionInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DriverInterface $driver,
        private readonly DatabaseConfig $database,
        private readonly TargetConfig $target,
        private readonly BackupService $backups,
        private readonly SqlExecutor $executor = new SqlExecutor(),
    ) {
    }


    public function inspect(): SchemaState
    {
        return $this->driver->inspect($this->pdo);
    }


    public function version(): string
    {
        return $this->driver->version($this->pdo);
    }


    /** @return list<string> */
    public function unsupportedObjects(): array
    {
        return $this->driver->unsupportedObjects($this->pdo);
    }


    public function apply(string $sql): void
    {
        $this->executor->apply($this->pdo, $sql, $this->database->driver);
    }


    public function backup(): string
    {
        return $this->backups->backup($this->database, $this->target);
    }
}
