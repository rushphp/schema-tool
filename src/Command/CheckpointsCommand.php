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


final class CheckpointsCommand extends CommandAbstract
{
    public function name(): string
    {
        return 'schema checkpoints';
    }

    public function description(): string
    {
        return 'List checkpoints or show one';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [
            $this->databaseOption(),
            new Option('show', 'Checkpoint to show', true, null, false, 'id', 's'),
        ];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $database = $this->database($input);
        $trace = $this->trace($database);
        $this->requireInitialized($trace);
        $show = $input->string('show');

        if ($show !== null && $show !== '') {
            $checkpoint = $trace->requireCheckpoint($show);
            $latest = $trace->latestCheckpointInAncestry((string) $trace->head());
            $this->out($output, $database, 'checkpoint: '. $checkpoint->id);
            $this->out($output, $database, 'save: '. $checkpoint->save);
            $this->out($output, $database, 'label: '. ($checkpoint->label ?? '-'));
            $this->out($output, $database, 'base: '. ($checkpoint->baseCheckpoint ?? '-'));
            $this->out($output, $database, 'schemaHash: '. $checkpoint->schemaHash);
            $this->out($output, $database, 'createdAt: '. $checkpoint->createdAt);
            $this->out($output, $database, 'verifiedAt: '. $checkpoint->verifiedAt);
            $this->out($output, $database, 'latest: '. ($latest?->id === $checkpoint->id ? 'yes' : 'no'));

            foreach ($checkpoint->artifacts as $name => $hash) {
                $this->out($output, $database, 'artifact '. $name .': '. $hash);
            }

            return ExitCode::Success->value;
        }

        $latest = $trace->latestCheckpointInAncestry((string) $trace->head());
        $checkpoints = $trace->allCheckpoints();

        if ($checkpoints === []) {
            $this->out($output, $database, 'no checkpoints');

            return ExitCode::Success->value;
        }

        foreach ($checkpoints as $checkpoint) {
            $marker = $latest?->id === $checkpoint->id ? '*' : ' ';
            $this->out(
                $output,
                $database,
                $marker .' '. $checkpoint->id
                .' save='. $checkpoint->save
                .' base='. ($checkpoint->baseCheckpoint ?? '-')
                .' label='. ($checkpoint->label ?? '-')
                .' hash='. $checkpoint->schemaHash,
            );
        }

        return ExitCode::Success->value;
    }
}
