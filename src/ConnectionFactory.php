<?php declare(strict_types=1);

namespace Rush\Tool\Schema;

use PDO;
use Rush\Tool\Schema\Config\DatabaseConfig;
use Rush\Tool\Schema\Config\TargetConfig;
use Rush\Tool\Schema\Exception\ConnectionException;
use Rush\Tool\Schema\Exception\TransportException;


final class ConnectionFactory
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }


    public function connect(
        DatabaseConfig $database,
        TargetConfig $target,
        bool $mustExist = false,
        bool $readOnly = false,
    ): PDO {
        if ( ! $target->isLocal()) {
            throw new TransportException("schema: transport '{$target->transport}' is not implemented yet");
        }

        return match ($database->driver) {
            'sqlite' => $this->sqlite($target, $mustExist, $readOnly),
            'mysql' => $this->mysql($target),
            default => throw new ConnectionException("schema: driver '{$database->driver}' is not implemented yet"),
        };
    }


    public function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return rtrim($this->projectRoot, '/') .'/'. ltrim($path, '/');
    }


    private function sqlite(TargetConfig $target, bool $mustExist, bool $readOnly): PDO
    {
        $path = $this->absolutePath($target->database);

        if ($mustExist && ! is_file($path)) {
            throw new ConnectionException("schema: sqlite database not found: {$path}");
        }

        $pdo = new PDO('sqlite:'. $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = '. ($readOnly ? 'OFF' : 'ON'));

        if ($readOnly) {
            $pdo->exec('PRAGMA query_only = ON');
        }

        return $pdo;
    }


    private function mysql(TargetConfig $target): PDO
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new ConnectionException('schema: ext-pdo_mysql is required for the mysql driver');
        }

        $dsn = 'mysql:host='. $target->host
            .';port='. ($target->port ?? 3306)
            .';dbname='. $target->database
            .';charset=utf8mb4';

        $pdo = new PDO($dsn, $target->user, $target->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]);

        return $pdo;
    }
}
