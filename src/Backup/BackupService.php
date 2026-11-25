<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Backup;

use Rush\Support\FileSystem;
use Rush\Tool\Schema\Config\DatabaseConfig;
use Rush\Tool\Schema\Config\TargetConfig;
use Rush\Tool\Schema\ConnectionFactory;
use Rush\Tool\Schema\Exception\SchemaException;
use Rush\Tool\Schema\Exception\TransportException;
use Rush\Tool\Schema\Paths\SchemaPaths;


class BackupService
{
    private ?string $dumpBinary = null;

    private bool $columnStatistics = false;


    public function __construct(
        private readonly SchemaPaths $paths,
        private readonly ConnectionFactory $connections,
    ) {
    }


    public function backup(DatabaseConfig $database, TargetConfig $target): string
    {
        if ( ! $target->isLocal()) {
            throw new TransportException("schema: backup for transport '{$target->transport}' is not implemented yet");
        }

        FileSystem::ensureDirectory($this->paths->backupsDirectory(), 0700);
        $stamp = gmdate('Ymd\THis\Z') .'-'. bin2hex(random_bytes(4));

        return match ($database->driver) {
            'sqlite' => $this->sqlite($target, $stamp),
            'mysql' => $this->mysql($target, $stamp),
            default => throw new SchemaException("schema: backup for driver '{$database->driver}' is not implemented"),
        };
    }


    private function sqlite(TargetConfig $target, string $stamp): string
    {
        $source = $this->connections->absolutePath($target->database);
        $destination = $this->paths->backupsDirectory() .'/'. $stamp .'.sqlite';

        FileSystem::copyFile($source, $destination);
        FileSystem::chmod($destination, 0600);

        return $destination;
    }


    private function mysql(TargetConfig $target, string $stamp): string
    {
        $destination = $this->paths->backupsDirectory() .'/'. $stamp .'.sql';
        $binary = $this->dumpBinary();
        $command = [
            $binary,
            '--single-transaction',
            '--no-tablespaces',
            '--routines',
            '--triggers',
            '--host='. ($target->host ?? '127.0.0.1'),
            '--port='. (string) ($target->port ?? 3306),
            '--user='. ($target->user ?? ''),
        ];

        if ($binary === 'mysqldump' && $this->supportsColumnStatistics($binary)) {
            $command[] = '--column-statistics=0';
        }

        $command[] = $target->database;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $destination, 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = array_merge(getenv(), ['MYSQL_PWD' => $target->password ?? '']);

        /** @var array<int, resource> $pipes */
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes, null, $env);

        if ( ! is_resource($process)) {
            throw new SchemaException('schema: could not start '. $binary .' for backup');
        }

        fclose($pipes[0]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0) {
            FileSystem::tryDelete($destination);
            throw new SchemaException(
                'schema: '. $binary .' backup failed'. (is_string($error) && trim($error) !== '' ? ': '. trim($error) : ''),
            );
        }

        FileSystem::chmod($destination, 0600);

        return $destination;
    }


    protected function dumpBinary(): string
    {
        if ($this->dumpBinary !== null) {
            return $this->dumpBinary;
        }

        foreach (['mariadb-dump', 'mysqldump'] as $binary) {
            if ($this->binaryExists($binary)) {
                return $this->dumpBinary = $binary;
            }
        }

        throw new SchemaException('schema: no compatible dump client found; install mariadb-client or mysql-client');
    }


    protected function binaryExists(string $binary): bool
    {
        $process = @proc_open(
            [$binary, '--version'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if ( ! is_resource($process)) {
            return false;
        }

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0;
    }


    protected function supportsColumnStatistics(string $binary): bool
    {
        if ($this->columnStatistics) {
            return true;
        }

        $process = @proc_open(
            [$binary, '--help'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if ( ! is_resource($process)) {
            return false;
        }

        fclose($pipes[0]);
        $help = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $this->columnStatistics = str_contains($help, 'column-statistics');
    }
}
