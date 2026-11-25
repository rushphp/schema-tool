<?php declare(strict_types=1);

namespace Rush\Tool\Schema;

use PDO;
use Rush\Tool\Schema\Canonical\SchemaHasher;
use Rush\Tool\Schema\Config\DatabaseConfig;
use Rush\Tool\Schema\Config\TargetConfig;
use Rush\Tool\Schema\Driver\DriverFactory;
use Rush\Tool\Schema\Driver\DriverInterface;
use Rush\Tool\Schema\Exception\DriverException;
use Rush\Tool\Schema\Exception\TraceException;
use Rush\Tool\Schema\Sql\SqlExecutor;
use Rush\Tool\Schema\Trace\Checkpoint;
use Rush\Tool\Schema\Trace\Save;
use Rush\Tool\Schema\Trace\TraceRepository;


final class CheckpointVerifier
{
    public function __construct(
        private readonly TraceRepository $trace,
        private readonly SqlExecutor $executor = new SqlExecutor(),
    ) {
    }


    /**
     * @return array<string, mixed>
     */
    public function verify(Checkpoint $checkpoint, DatabaseConfig $database): array
    {
        return $database->driver === 'sqlite'
            ? $this->verifySqlite($checkpoint, $database)
            : $this->verifyMysql($checkpoint, $database);
    }


    /**
     * @return array<string, mixed>
     */
    private function verifySqlite(Checkpoint $checkpoint, DatabaseConfig $database): array
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $this->run($checkpoint, $database, $pdo, 'sqlite');
    }


    /**
     * @return array<string, mixed>
     */
    private function verifyMysql(Checkpoint $checkpoint, DatabaseConfig $database): array
    {
        $target = $database->local();

        if ( ! $target->isLocal()) {
            throw new DriverException('schema: mysql checkpoint verification requires a local target');
        }

        if (!extension_loaded('pdo_mysql')) {
            throw new DriverException('schema: ext-pdo_mysql is required for mysql checkpoint verification');
        }

        $server = $this->mysqlServer($target);
        $scratch = '__rush_verify_'. bin2hex(random_bytes(6));
        $server->exec('CREATE DATABASE `'. $scratch .'`');

        try {
            $pdo = new PDO(
                'mysql:host='. $target->host .';port='. ($target->port ?? 3306) .';dbname='. $scratch .';charset=utf8mb4',
                $target->user,
                $target->password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true],
            );

            return $this->run($checkpoint, $database, $pdo, 'mysql');
        } finally {
            $server->exec('DROP DATABASE IF EXISTS `'. $scratch .'`');
        }
    }


    /**
     * @return array<string, mixed>
     */
    private function run(Checkpoint $checkpoint, DatabaseConfig $database, PDO $pdo, string $driverName): array
    {
        $driver = DriverFactory::make($driverName);
        $base = $checkpoint->baseCheckpoint === null
            ? null
            : $this->trace->requireCheckpoint($checkpoint->baseCheckpoint);

        if ($base === null) {
            $this->executor->apply($pdo, $this->trace->checkpointRelease($checkpoint), $driverName);
            $hash = $this->hash($pdo, $driver);

            if ( ! hash_equals($checkpoint->schemaHash, $hash)) {
                throw new TraceException("schema: genesis release does not reproduce checkpoint {$checkpoint->id}");
            }

            return [
                'checkpoint' => $checkpoint->id,
                'base' => null,
                'saves' => [],
                'schemaHash' => $hash,
            ];
        }

        $this->executor->apply($pdo, $this->trace->checkpointRelease($base), $driverName);
        $hash = $this->hash($pdo, $driver);

        if ( ! hash_equals($base->schemaHash, $hash)) {
            throw new TraceException("schema: base release does not reproduce checkpoint {$base->id}");
        }

        $saves = $this->trace->savesForCheckpoint($checkpoint);

        foreach ($saves as $save) {
            $this->executor->apply($pdo, $this->trace->saveUp($save), $driverName);
            $this->assertHash($pdo, $driver, $save->toHash, "up {$save->id}");
        }

        $hash = $this->hash($pdo, $driver);

        if ( ! hash_equals($checkpoint->schemaHash, $hash)) {
            throw new TraceException("schema: checkpoint {$checkpoint->id} hash mismatch after up replay");
        }

        foreach (array_reverse($saves) as $save) {
            $this->executor->apply($pdo, $this->trace->saveDown($save), $driverName);

            if ($save->fromHash !== null) {
                $this->assertHash($pdo, $driver, $save->fromHash, "down {$save->id}");
            }
        }

        $hash = $this->hash($pdo, $driver);

        if ( ! hash_equals($base->schemaHash, $hash)) {
            throw new TraceException("schema: checkpoint {$checkpoint->id} hash mismatch after down replay");
        }

        return [
            'checkpoint' => $checkpoint->id,
            'base' => $base->id,
            'saves' => array_map(static fn (Save $save): string => $save->id, $saves),
            'schemaHash' => $hash,
        ];
    }


    private function mysqlServer(TargetConfig $target): PDO
    {
        return new PDO(
            'mysql:host='. $target->host .';port='. ($target->port ?? 3306) .';charset=utf8mb4',
            $target->user,
            $target->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }


    private function hash(PDO $pdo, DriverInterface $driver): string
    {
        return (new SchemaHasher())->hash($driver->inspect($pdo));
    }


    private function assertHash(PDO $pdo, DriverInterface $driver, string $expected, string $step): void
    {
        $hash = $this->hash($pdo, $driver);

        if ( ! hash_equals($expected, $hash)) {
            throw new TraceException(
                "schema: replay mismatch at {$step}: expected {$expected}, got {$hash}",
            );
        }
    }
}
