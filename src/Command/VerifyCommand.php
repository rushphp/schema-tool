<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Command;

use Rush\Contract\ExitCode;
use Rush\Contract\InputInterface;
use Rush\Contract\Option;
use Rush\Contract\OutputInterface;
use Rush\Tool\Schema\Canonical\SchemaHasher;
use Rush\Tool\Schema\CheckpointVerifier;
use Rush\Tool\Schema\Exception\TraceException;
use Rush\Tool\Schema\Trace\Save;
use Rush\Tool\Schema\Trace\TraceRepository;


final class VerifyCommand extends CommandAbstract
{
    public function name(): string
    {
        return 'schema verify';
    }

    public function description(): string
    {
        return 'Verify trace integrity or replay a checkpoint';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [
            $this->databaseOption(),
            new Option('checkpoint', 'Checkpoint to verify', true, null, false, 'id', 'c'),
        ];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $database = $this->database($input);
        $trace = $this->trace($database);
        $this->requireInitialized($trace);

        try {
            $id = $input->string('checkpoint');

            if ($id !== null && $id !== '') {
                $checkpoint = $trace->requireCheckpoint($id);
                $this->verifier($trace)->verify($checkpoint, $database);
                $this->out($output, $database, 'checkpoint '. $checkpoint->id .': OK');

                return ExitCode::Success->value;
            }

            $this->verifyStore($trace);

            foreach ($trace->allCheckpoints() as $checkpoint) {
                $this->verifier($trace)->verify($checkpoint, $database);
                $this->out($output, $database, 'checkpoint '. $checkpoint->id .': OK');
            }

            $this->out($output, $database, 'verify: OK');

            return ExitCode::Success->value;
        } catch (TraceException $exception) {
            $this->out($output, $database, 'verify: FAILED');
            $output->error($exception->getMessage());

            return ExitCode::CheckFailed->value;
        }
    }


    private function verifier(TraceRepository $trace): CheckpointVerifier
    {
        return new CheckpointVerifier($trace);
    }


    private function verifyStore(TraceRepository $trace): void
    {
        $chain = $trace->chain();
        $hasher = new SchemaHasher();
        $previous = null;

        foreach ($chain as $save) {
            $state = $trace->state($save->toHash);
            $hash = $hasher->hash($state);

            if ( ! hash_equals($save->toHash, $hash)) {
                throw new TraceException("schema: snapshot hash mismatch for save {$save->id}");
            }

            $up = $trace->saveUp($save);
            $down = $trace->saveDown($save);

            if ( ! hash_equals($save->upHash, SchemaHasher::hashSql($up))) {
                throw new TraceException("schema: up artifact hash mismatch for save {$save->id}");
            }

            if ( ! hash_equals($save->downHash, SchemaHasher::hashSql($down))) {
                throw new TraceException("schema: down artifact hash mismatch for save {$save->id}");
            }

            $this->verifyParent($save, $previous);
            $previous = $save;
        }

        foreach ($trace->allCheckpoints() as $checkpoint) {
            if ($trace->save($checkpoint->save) === null) {
                throw new TraceException("schema: checkpoint {$checkpoint->id} references missing save {$checkpoint->save}");
            }

            if ($checkpoint->baseCheckpoint !== null && $trace->checkpoint($checkpoint->baseCheckpoint) === null) {
                throw new TraceException("schema: checkpoint {$checkpoint->id} references missing base");
            }
        }
    }


    private function verifyParent(Save $save, ?Save $previous): void
    {
        if ($previous === null) {
            if ($save->parent !== null) {
                throw new TraceException("schema: genesis save {$save->id} must not have a parent");
            }

            return;
        }

        if ($save->parent !== $previous->id) {
            throw new TraceException("schema: save {$save->id} does not follow its parent {$previous->id}");
        }

        if ( ! hash_equals($previous->toHash, (string) $save->fromHash)) {
            throw new TraceException("schema: save {$save->id} fromHash does not match parent");
        }
    }
}
