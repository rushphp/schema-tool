<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Command;

use Rush\Contract\ExitCode;
use Rush\Contract\InputInterface;
use Rush\Contract\Option;
use Rush\Contract\OutputInterface;
use Rush\Tool\Schema\Canonical\SchemaHasher;
use Rush\Tool\Schema\Exception\UnsupportedObjectsException;
use Rush\Tool\Schema\Trace\Checkpoint;
use Rush\Tool\Schema\Trace\Save;
use Rush\Tool\Schema\Ulid;


final class InitCommand extends CommandAbstract
{
    public function name(): string
    {
        return 'schema init';
    }

    public function description(): string
    {
        return 'Capture the local database as GENESIS';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [$this->databaseOption()];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $database = $this->database($input);
        $paths = $this->paths($database);
        $trace = $this->trace($database);

        if ($trace->initialized()) {
            $this->out($output, $database, 'already initialized');

            return ExitCode::Failure->value;
        }

        $session = $this->session($database, $this->localTarget($database), true);
        $driver = $this->driver($database);
        $unsupported = $session->unsupportedObjects();

        if ($unsupported !== []) {
            throw UnsupportedObjectsException::fromObjects($unsupported);
        }

        $state = $session->inspect();
        $hasher = new SchemaHasher();
        $hash = $hasher->hash($state);
        $dialect = $driver->dialect();
        $release = $dialect->release($state);
        $now = gmdate('c');
        $saveId = Ulid::generate();

        $trace->writeState($state);
        $trace->writeSave(new Save(
            $saveId,
            null,
            'GENESIS',
            $now,
            null,
            $hash,
            SchemaHasher::hashSql(''),
            SchemaHasher::hashSql(''),
            false,
        ), '', '');

        $checkpointId = 'cp_'. Ulid::generate();
        $trace->writeCheckpoint(new Checkpoint(
            $checkpointId,
            $saveId,
            'GENESIS',
            null,
            $hash,
            $now,
            $now,
            ['release' => SchemaHasher::hashSql($release)],
        ), $release);
        $trace->setHead($saveId);

        $this->out($output, $database, 'genesis created');
        $this->out($output, $database, 'save: '. $saveId);
        $this->out($output, $database, 'checkpoint: '. $checkpointId);
        $this->out($output, $database, 'schemaHash: '. $hash);
        $this->out($output, $database, 'tables: '. count($state->tables) .', views: '. count($state->views));
        $this->out($output, $database, 'HEAD: '. $saveId);

        return ExitCode::Success->value;
    }
}
