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
use Rush\Tool\Schema\Trace\Checkpoint;
use Rush\Tool\Schema\Trace\Save;
use Rush\Tool\Schema\Ulid;


final class RegressCommand extends CommandAbstract
{
    public function name(): string
    {
        return 'schema regress';
    }

    public function description(): string
    {
        return 'Create a forward save that regresses the local database to an earlier save';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [
            $this->databaseOption(),
            new Option('to', 'Target save or checkpoint', true, null, true, 'id', 't'),
            new Option('checkpoint', 'Create a checkpoint after regressing', false, null, false, 'value', 'c'),
            new Option('allow-destructive', 'Allow destructive changes', false, null, false, 'value'),
            new Option('yes', 'Skip destructive confirmation', false, null, false, 'value'),
        ];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $database = $this->database($input);
        $trace = $this->trace($database);
        $this->requireInitialized($trace);

        $headId = (string) $trace->head();
        $head = $trace->requireSave($headId);
        $target = $this->targetSave($trace, (string) $input->string('to'));

        if ($headId === $target->id) {
            $this->out($output, $database, 'already at '. $target->id);

            return ExitCode::Success->value;
        }

        if ( ! $trace->isAncestor($target->id, $headId)) {
            throw new TraceException("schema: {$target->id} is not an ancestor of HEAD; regress only moves backwards");
        }

        $session = $this->session($database, $this->localTarget($database), true, true);
        $driver = $this->driver($database);
        $live = (new SchemaHasher())->hash($session->inspect());

        if ( ! hash_equals($head->toHash, $live)) {
            throw new TraceException('schema: local database has uncommitted changes; run schema save first');
        }

        $from = $trace->state($head->toHash);
        $to = $trace->state($target->toHash);
        $diff = SchemaDiff::between($from, $to);

        if ($diff->isEmpty()) {
            throw new TraceException('schema: regression diff is empty; cannot create a save');
        }

        if ($diff->destructive) {
            $this->confirmDestructive(
                $input,
                $output,
                'schema: regression contains destructive changes (data loss possible).',
            );
        }

        $backup = $session->backup();
        $dialect = $driver->dialect();
        $up = $dialect->sql($diff);
        $down = $dialect->sql(SchemaDiff::between($to, $from));

        $session->apply($up);

        $after = (new SchemaHasher())->hash($session->inspect());

        if ( ! hash_equals($target->toHash, $after)) {
            throw new TraceException("schema: regression did not reach {$target->toHash}; backup at {$backup}");
        }

        $id = Ulid::generate();
        $trace->writeState($to);
        $trace->writeSave(new Save(
            $id,
            $head->id,
            'regress to '. $target->id,
            gmdate('c'),
            $head->toHash,
            $target->toHash,
            SchemaHasher::hashSql($up),
            SchemaHasher::hashSql($down),
            $diff->destructive,
        ), $up, $down);
        $trace->setHead($id);

        $this->out($output, $database, 'regression saved');
        $this->out($output, $database, 'save: '. $id);
        $this->out($output, $database, 'target: '. $target->id);
        $this->out($output, $database, 'schemaHash: '. $target->toHash);
        $this->out($output, $database, 'backup: '. $backup);

        if ($input->bool('checkpoint')) {
            $service = new CheckpointService($trace, new CheckpointVerifier($trace));
            $checkpoint = $service->create($database, 'regress to '. $target->id);
            $this->out($output, $database, 'checkpoint: '. $checkpoint->id);
        }

        $this->out($output, $database, 'HEAD: '. $id);

        return ExitCode::Success->value;
    }


    private function targetSave(\Rush\Tool\Schema\Trace\TraceRepository $trace, string $id): Save
    {
        $checkpoint = $trace->checkpoint($id);

        if ($checkpoint instanceof Checkpoint) {
            return $trace->requireSave($checkpoint->save);
        }

        return $trace->requireSave($id);
    }
}
