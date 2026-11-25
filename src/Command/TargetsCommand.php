<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Command;

use Rush\Contract\ExitCode;
use Rush\Contract\InputInterface;
use Rush\Contract\OutputInterface;


final class TargetsCommand extends CommandAbstract
{
    public function name(): string
    {
        return 'schema targets';
    }

    public function description(): string
    {
        return 'List configured targets';
    }

    /** @return list<\Rush\Contract\Option> */
    protected function commandOptions(): array
    {
        return [$this->databaseOption()];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $database = $this->database($input);
        $trace = $this->trace($database);
        $this->requireInitialized($trace);

        foreach ($database->names() as $name) {
            $target = $database->target($name);
            $record = $trace->targetRecord($name);
            $extra = '';

            if ($record !== null) {
                $checkpoint = $record['checkpoint'] ?? null;
                $verifiedAt = $record['verifiedAt'] ?? null;
                $extra = ' last='. (is_string($checkpoint) ? $checkpoint : '-')
                    .' at='. (is_string($verifiedAt) ? $verifiedAt : '-')
                    .' flavor='. (is_string($record['flavor'] ?? null) ? $record['flavor'] : '-')
                    .' version='. (is_string($record['version'] ?? null) ? $record['version'] : '-');
            }

            $this->out(
                $output,
                $database,
                $name
                .' transport='. $target->transport
                .' driver='. $database->driver
                .' database='. $target->database
                .$extra,
            );
        }

        return ExitCode::Success->value;
    }
}
