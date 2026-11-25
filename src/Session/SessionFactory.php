<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Session;

use Rush\Tool\Schema\Backup\BackupService;
use Rush\Tool\Schema\Config\DatabaseConfig;
use Rush\Tool\Schema\Config\TargetConfig;
use Rush\Tool\Schema\ConnectionFactory;
use Rush\Tool\Schema\Driver\DriverFactory;
use Rush\Tool\Schema\Paths\SchemaPaths;
use Rush\Tool\Schema\Transport\AgentClient;


final class SessionFactory
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ConnectionFactory $connections,
    ) {
    }


    public function create(
        DatabaseConfig $database,
        TargetConfig $target,
        bool $mustExist = false,
        bool $forWrite = false,
    ): SessionInterface {
        if ($target->isLocal()) {
            $pdo = $this->connections->connect($database, $target, $mustExist, ! $forWrite);

            return new LocalSession(
                $pdo,
                DriverFactory::make($database->driver),
                $database,
                $target,
                new BackupService(new SchemaPaths($this->projectRoot, $database->name), $this->connections),
            );
        }

        return new RemoteSession(new AgentClient($this->projectRoot, $database, $target));
    }
}
