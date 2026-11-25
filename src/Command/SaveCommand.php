<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Command;

use Rush\Contract\ExitCode;
use Rush\Contract\InputInterface;
use Rush\Contract\Option;
use Rush\Contract\OutputInterface;
use Rush\Tool\Schema\Canonical\SchemaHasher;
use Rush\Tool\Schema\CheckpointService;
use Rush\Tool\Schema\CheckpointVerifier;
use Rush\Tool\Schema\Diff\SchemaDiff;
use Rush\Tool\Schema\Exception\TraceException;
use Rush\Tool\Schema\Trace\Save;
use Rush\Tool\Schema\Ulid;


final class SaveCommand extends CommandAbstract
{
    public function name(): string
    {
        return 'schema save';
    }

    public function description(): string
    {
        return 'Save local structure changes as a new save';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [
            $this->databaseOption(),
            new Option('label', 'Save label', true, null, false, 'label', 'l'),
            new Option('checkpoint', 'Create a checkpoint after saving', false, null, false, 'value', 'c'),
        ];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $database = $this->database($input);
        $trace = $this->trace($database);
        $this->requireInitialized($trace);

        $session = $this->session($database, $this->localTarget($database), true);
        $driver = $this->driver($database);
        $state = $session->inspect();
        $hash = (new SchemaHasher())->hash($state);
        $head = $trace->requireSave((string) $trace->head());

        if (hash_equals($head->toHash, $hash)) {
            $this->out($output, $database, 'nothing to save');

            return ExitCode::Success->value;
        }

        $previous = $trace->state($head->toHash);
        $diff = SchemaDiff::between($previous, $state);

        if ($diff->isEmpty()) {
            throw new TraceException('schema: structure changed but diff is empty; cannot create a save');
        }

        $dialect = $driver->dialect();
        $up = $dialect->sql($diff);
        $down = $dialect->sql(SchemaDiff::between($state, $previous));
        $now = gmdate('c');
        $id = Ulid::generate();

        $trace->writeState($state);
        $trace->writeSave(new Save(
            $id,
            $head->id,
            $input->string('label'),
            $now,
            $head->toHash,
            $hash,
            SchemaHasher::hashSql($up),
            SchemaHasher::hashSql($down),
            $diff->destructive,
        ), $up, $down);
        $trace->setHead($id);

        $this->out($output, $database, 'save created');
        $this->out($output, $database, 'save: '. $id);
        $this->out($output, $database, 'schemaHash: '. $hash);
        $this->out($output, $database, 'changes: '. $this->changes($diff));
        $this->out($output, $database, 'destructive: '. ($diff->destructive ? 'yes' : 'no'));

        if ($input->bool('checkpoint')) {
            $service = new CheckpointService($trace, new CheckpointVerifier($trace));
            $checkpoint = $service->create($database, $input->string('label'));
            $this->out($output, $database, 'checkpoint: '. $checkpoint->id);
        }

        $this->out($output, $database, 'HEAD: '. $id);

        return ExitCode::Success->value;
    }


    private function changes(SchemaDiff $diff): string
    {
        $parts = [];

        foreach ($diff->summary() as $type => $count) {
            if ($count > 0) {
                $parts[] = $type .'='. $count;
            }
        }

        return $parts === [] ? 'none' : implode(', ', $parts);
    }
}
