<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Command;

use Rush\Contract\ExitCode;
use Rush\Contract\InputInterface;
use Rush\Contract\Option;
use Rush\Contract\OutputInterface;
use Rush\Tool\Schema\Canonical\SchemaHasher;
use Rush\Tool\Schema\Diff\SchemaDiff;
use Rush\Tool\Schema\Exception\TraceException;
use Rush\Tool\Schema\Trace\Save;
use Rush\Tool\Schema\Trace\TraceRepository;


final class PushCommand extends CommandAbstract
{
    public function name(): string
    {
        return 'schema push';
    }

    public function description(): string
    {
        return 'Reconcile a target forward to a checkpoint';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [
            $this->databaseOption(),
            new Option('target', 'Target name', true, null, false, 'name', 'T'),
            new Option('checkpoint', 'Checkpoint to push', true, null, false, 'id', 'c'),
            new Option('allow-destructive', 'Allow destructive changes', false, null, false, 'value'),
            new Option('yes', 'Skip destructive confirmation', false, null, false, 'value'),
        ];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $database = $this->database($input);
        $trace = $this->trace($database);
        $this->requireInitialized($trace);
        $checkpoint = $this->checkpoint($trace, $input->string('checkpoint'));
        $targetName = $input->string('target') ?? 'local';

        if ($targetName === 'local' && $checkpoint->save !== $trace->head()) {
            throw new TraceException('schema: local push can only sync to the HEAD checkpoint; use schema regress for rollback');
        }

        $targetConfig = $database->target($targetName);
        $session = $this->session($database, $targetConfig, true, true);
        $driver = $this->driver($database);
        $targetState = $session->inspect();
        $targetHash = (new SchemaHasher())->hash($targetState);

        if (hash_equals($checkpoint->schemaHash, $targetHash)) {
            $this->out($output, $database, 'target '. $targetName .' is already at '. $checkpoint->id);

            return ExitCode::Success->value;
        }

        $matches = $this->matchingSaves($trace, $targetHash);

        if ($matches === []) {
            throw new TraceException("schema: target '{$targetName}' state is unknown; cannot push forward");
        }

        $source = null;

        foreach ($matches as $match) {
            if ($trace->isAncestor($match->id, $checkpoint->save)) {
                $source = $match;
                break;
            }
        }

        if ( ! $source instanceof Save) {
            throw new TraceException("schema: target '{$targetName}' is ahead or divergent; create a regression save and push it");
        }

        $desired = $trace->state($checkpoint->schemaHash);
        $diff = SchemaDiff::between($targetState, $desired);

        if ($diff->isEmpty()) {
            $this->out($output, $database, 'target '. $targetName .' has no structural difference');

            return ExitCode::Success->value;
        }

        if ($diff->destructive) {
            $this->confirmDestructive(
                $input,
                $output,
                "schema: push to {$targetName} contains destructive changes (data loss possible).",
            );
        }

        $backup = $session->backup();
        $sql = $driver->dialect()->sql($diff);

        $session->apply($sql);

        $after = (new SchemaHasher())->hash($session->inspect());

        if ( ! hash_equals($checkpoint->schemaHash, $after)) {
            throw new TraceException("schema: push did not reach {$checkpoint->schemaHash}; backup at {$backup}");
        }

        $trace->writeTargetRecord($targetName, [
            'format' => 1,
            'target' => $targetName,
            'transport' => $targetConfig->transport,
            'driver' => $database->driver,
            'checkpoint' => $checkpoint->id,
            'save' => $checkpoint->save,
            'schemaHash' => $after,
            'verifiedAt' => gmdate('c'),
            'backup' => $backup,
        ]);

        $this->out($output, $database, 'push complete');
        $this->out($output, $database, 'target: '. $targetName);
        $this->out($output, $database, 'from: '. $source->id);
        $this->out($output, $database, 'checkpoint: '. $checkpoint->id);
        $this->out($output, $database, 'schemaHash: '. $after);
        $this->out($output, $database, 'backup: '. $backup);

        return ExitCode::Success->value;
    }


    /** @return list<Save> */
    private function matchingSaves(TraceRepository $trace, string $hash): array
    {
        return array_values(array_filter(
            $trace->allSaves(),
            static fn (Save $save): bool => hash_equals($save->toHash, $hash),
        ));
    }
}
