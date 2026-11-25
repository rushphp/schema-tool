<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Backup;

use Rush\Support\FileSystem;
use Rush\Tool\Schema\Config\DatabaseConfig;
use Rush\Tool\Schema\Config\TargetConfig;
use Rush\Tool\Schema\ConnectionFactory;
use Rush\Tool\Schema\Exception\SchemaException;
use Rush\Tool\Schema\Exception\TransportException;
use Rush\Tool\Schema\Paths\SchemaPaths;


final class BackupService
{
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
        $command = [
            'mysqldump',
            '--single-transaction',
            '--no-tablespaces',
            '--routines',
            '--triggers',
            '--host='. ($target->host ?? '127.0.0.1'),
            '--port='. (string) ($target->port ?? 3306),
            '--user='. ($target->user ?? ''),
            $target->database,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $destination, 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = getenv();
        $env['MYSQL_PWD'] = $target->password ?? '';

        /** @var array<int, resource> $pipes */
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, null, $env);

        if ( ! is_resource($process)) {
            throw new SchemaException('schema: could not start mysqldump for backup');
        }

        fclose($pipes[0]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0) {
            FileSystem::tryDelete($destination);
            throw new SchemaException('schema: mysqldump backup failed'. (is_string($error) && trim($error) !== '' ? ': '. trim($error) : ''));
        }

        FileSystem::chmod($destination, 0600);

        return $destination;
    }
}
