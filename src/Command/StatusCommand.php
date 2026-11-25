<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Command;

use Rush\Contract\ExitCode;
use Rush\Contract\InputInterface;
use Rush\Contract\Option;
use Rush\Contract\OutputInterface;
use Rush\Tool\Schema\Canonical\SchemaHasher;
use Rush\Tool\Schema\Diff\SchemaDiff;
use Rush\Tool\Schema\Exception\TraceException;
use Rush\Tool\Schema\Trace\Checkpoint;
use Rush\Tool\Schema\Trace\Save;
use Rush\Tool\Schema\Trace\TraceRepository;


final class StatusCommand extends CommandAbstract
{
    public function name(): string
    {
        return 'schema status';
    }

    public function description(): string
    {
        return 'Show local schema state or compare a target';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [
            $this->databaseOption(),
            new Option('target', 'Target to compare', true, null, false, 'name', 'T'),
            new Option('json', 'Output JSON', false, null, false, 'value', 'j'),
        ];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $database = $this->database($input);
        $trace = $this->trace($database);

        if ( ! $trace->initialized()) {
            $this->out($output, $database, 'not initialized');

            return ExitCode::Failure->value;
        }

        $target = $input->string('target');

        return $target === null || $target === ''
            ? $this->local($input, $output, $trace)
            : $this->target($input, $output, $trace, $target);
    }


    private function local(InputInterface $input, OutputInterface $output, TraceRepository $trace): int
    {
        $database = $this->database($input);
        $head = $trace->requireSave((string) $trace->head());
        $latest = $trace->latestCheckpointInAncestry($head->id);
        $session = $this->session($database, $this->localTarget($database), true);
        $state = $session->inspect();
        $hash = (new SchemaHasher())->hash($state);
        $dirty = ! hash_equals($head->toHash, $hash);
        $diff = $dirty ? SchemaDiff::between($trace->state($head->toHash), $state) : new SchemaDiff([], false);
        $headCheckpoint = $trace->checkpointForSave($head->id);
        $savesSince = $latest === null ? [] : $this->savesAfter($trace, $latest->save);

        $data = [
            'database' => $database->name,
            'driver' => $database->driver,
            'head' => $head->id,
            'headLabel' => $head->label,
            'headHash' => $head->toHash,
            'liveHash' => $hash,
            'dirty' => $dirty,
            'checkpoint' => $latest?->id,
            'checkpointSave' => $latest?->save,
            'headHasCheckpoint' => $headCheckpoint !== null,
            'savesSinceCheckpoint' => count($savesSince),
            'changes' => $diff->summary(),
            'destructive' => $diff->destructive,
            'checkpoints' => count($trace->allCheckpoints()),
        ];

        if ($input->bool('json')) {
            $output->line($this->json($data));
        } else {
            $this->out($output, $database, 'HEAD: '. $head->id . ($head->label === null ? '' : ' ('. $head->label .')'));
            $this->out($output, $database, 'checkpoint: '. ($latest === null ? "-" : $latest->id));
            $this->out($output, $database, 'schemaHash: '. $head->toHash);
            $this->out($output, $database, 'localHash: '. $hash);
            $this->out($output, $database, 'dirty: '. ($dirty ? 'yes' : 'no'));
            $this->out($output, $database, 'saves since checkpoint: '. count($savesSince));
            $this->out($output, $database, 'checkpoints: '. count($trace->allCheckpoints()));

            if ($dirty) {
                $this->out($output, $database, 'changes: '. $this->changes($diff));
                $this->out($output, $database, 'destructive: '. ($diff->destructive ? 'yes' : 'no'));
            }
        }

        $clean = ! $dirty && $headCheckpoint !== null;

        return $clean ? ExitCode::Success->value : ExitCode::CheckFailed->value;
    }


    private function target(
        InputInterface $input,
        OutputInterface $output,
        TraceRepository $trace,
        string $target,
    ): int {
        $database = $this->database($input);
        $targetConfig = $database->target($target);
        $session = $this->session($database, $targetConfig, true);
        $state = $session->inspect();
        $hash = (new SchemaHasher())->hash($state);
        $latest = $trace->latestCheckpoint();

        if ($latest === null) {
            throw new TraceException('schema: no checkpoint found; run schema checkpoint');
        }

        $matches = $this->matchingSaves($trace, $hash);
        $checkpoints = $this->matchingCheckpoints($trace, $hash);
        $latestState = $trace->state($latest->schemaHash);
        $diff = SchemaDiff::between($state, $latestState);
        $relation = $this->relation($trace, $hash, $latest, $matches);
        $forwardPossible = $this->forwardPossible($trace, $latest, $matches);

        $data = [
            'database' => $database->name,
            'target' => $target,
            'transport' => $targetConfig->transport,
            'driver' => $database->driver,
            'schemaHash' => $hash,
            'checkpoint' => $latest->id,
            'checkpointHash' => $latest->schemaHash,
            'relation' => $relation,
            'forwardPossible' => $forwardPossible,
            'matches' => array_map(static fn (Save $save): string => $save->id, $matches),
            'checkpoints' => array_map(static fn (Checkpoint $checkpoint): string => $checkpoint->id, $checkpoints),
            'changesToCheckpoint' => $diff->summary(),
            'destructive' => $diff->destructive,
        ];

        if ($input->bool('json')) {
            $output->line($this->json($data));
        } else {
            $this->out($output, $database, 'target: '. $target .' ('. $targetConfig->transport .')');
            $this->out($output, $database, 'schemaHash: '. $hash);
            $this->out($output, $database, 'checkpoint: '. $latest->id);
            $this->out($output, $database, 'relation: '. $relation);
            $this->out($output, $database, 'matches: '. (($matches === []) ? 'none' : implode(', ', array_map(static fn (Save $save): string => $save->id, $matches))));
            $this->out($output, $database, 'forward possible: '. ($forwardPossible ? 'yes' : 'no'));
            $this->out($output, $database, 'changes to checkpoint: '. $this->changes($diff));
            $this->out($output, $database, 'destructive: '. ($diff->destructive ? 'yes' : 'no'));
        }

        return $relation === 'current' ? ExitCode::Success->value : ExitCode::CheckFailed->value;
    }


    /** @return list<Save> */
    private function matchingSaves(TraceRepository $trace, string $hash): array
    {
        return array_values(array_filter(
            $trace->allSaves(),
            static fn (Save $save): bool => hash_equals($save->toHash, $hash),
        ));
    }


    /** @return list<Checkpoint> */
    private function matchingCheckpoints(TraceRepository $trace, string $hash): array
    {
        return array_values(array_filter(
            $trace->allCheckpoints(),
            static fn (Checkpoint $checkpoint): bool => hash_equals($checkpoint->schemaHash, $hash),
        ));
    }


    /** @param list<Save> $matches */
    private function relation(TraceRepository $trace, string $hash, Checkpoint $latest, array $matches): string
    {
        if (hash_equals($latest->schemaHash, $hash)) {
            return 'current';
        }

        foreach ($matches as $match) {
            if ($trace->isAncestor($match->id, $latest->save)) {
                return 'behind';
            }

            if ($trace->isAncestor($latest->save, $match->id)) {
                return 'ahead';
            }
        }

        return 'unknown';
    }


    /** @param list<Save> $matches */
    private function forwardPossible(TraceRepository $trace, Checkpoint $latest, array $matches): bool
    {
        foreach ($matches as $match) {
            if ($trace->isAncestor($match->id, $latest->save)) {
                return true;
            }
        }

        return false;
    }


    /** @return list<Save> */
    private function savesAfter(TraceRepository $trace, string $save): array
    {
        $chain = $trace->chain();
        $saves = [];
        $after = false;

        foreach ($chain as $item) {
            if ($after) {
                $saves[] = $item;
            }

            if ($item->id === $save) {
                $after = true;
            }
        }

        return $saves;
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


    /** @param array<string, mixed> $data */
    private function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
