<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Command;

use Rush\Contract\ExitCode;
use Rush\Contract\InputInterface;
use Rush\Contract\Option;
use Rush\Contract\OutputInterface;
use Rush\Tool\Schema\Canonical\SchemaHasher;
use Rush\Tool\Schema\Connection;
use Rush\Tool\Schema\Driver\SqliteDriver;
use Rush\Tool\Schema\Exception\UnsupportedObjectsException;
use Rush\Tool\Schema\Repository\SchemaRepository;
use Rush\Tool\Schema\Sql\SqliteSqlGenerator;


final class InitCommand extends CommandAbstract
{
    public function name(): string
    {
        return 'schema init';
    }

    public function description(): string
    {
        return 'Capture the local database structure as Genesis';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [
            new Option('database', 'Database name', true, null, false, 'name'),
        ];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $database = $this->database($input);
        $target = $database->local();

        $repository = new SchemaRepository($this->projectRoot, $database->name);

        if ($repository->initialized()) {
            $this->out($output, $database->name, 'already initialized');

            return ExitCode::Failure->value;
        }

        if ($database->driver !== 'sqlite') {
            $this->out($output, $database->name, "driver '{$database->driver}' is not implemented yet");

            return ExitCode::Failure->value;
        }

        $pdo = Connection::connect($this->projectRoot, $database, $target);
        $driver = new SqliteDriver();
        $unsupported = $driver->unsupportedObjects($pdo);

        if ($unsupported !== []) {
            throw UnsupportedObjectsException::fromObjects($unsupported);
        }

        $state = $driver->inspect($pdo);
        $hash = (new SchemaHasher())->hash($state);
        $releaseSql = (new SqliteSqlGenerator())->release($state);

        $repository->writeGenesis(
            $database,
            $state,
            $hash,
            $releaseSql,
            $driver->version($pdo),
        );

        $this->out($output, $database->name, 'genesis created');
        $this->out($output, $database->name, 'schemaHash: '. $hash);
        $this->out($output, $database->name, 'tables: '. count($state->tables) .', views: '. count($state->views));
        $this->out($output, $database->name, 'HEAD: GENESIS');

        return ExitCode::Success->value;
    }


    private function out(OutputInterface $output, string $database, string $message): void
    {
        $output->line('['. $database .'] '. $message);
    }
}
