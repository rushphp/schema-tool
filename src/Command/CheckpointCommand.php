<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Command;

use Rush\Contract\ExitCode;
use Rush\Contract\InputInterface;
use Rush\Contract\Option;
use Rush\Contract\OutputInterface;
use Rush\Tool\Schema\CheckpointService;
use Rush\Tool\Schema\CheckpointVerifier;


final class CheckpointCommand extends CommandAbstract
{
    public function name(): string
    {
        return 'schema checkpoint';
    }

    public function description(): string
    {
        return 'Verify and tag the current HEAD as a checkpoint';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [
            $this->databaseOption(),
            new Option('label', 'Checkpoint label', true, null, false, 'label', 'l'),
        ];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $database = $this->database($input);
        $trace = $this->trace($database);
        $this->requireInitialized($trace);

        $service = new CheckpointService($trace, new CheckpointVerifier($trace));
        $checkpoint = $service->create($database, $input->string('label'));

        $this->out($output, $database, 'checkpoint created');
        $this->out($output, $database, 'checkpoint: '. $checkpoint->id);
        $this->out($output, $database, 'save: '. $checkpoint->save);
        $this->out($output, $database, 'base: '. ($checkpoint->baseCheckpoint ?? '-'));
        $this->out($output, $database, 'schemaHash: '. $checkpoint->schemaHash);
        $this->out($output, $database, 'verified: yes');

        return ExitCode::Success->value;
    }
}
