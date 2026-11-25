<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Command;

use Rush\Contract\ExitCode;
use Rush\Contract\InputInterface;
use Rush\Contract\Option;
use Rush\Contract\OutputInterface;
use Rush\Support\FileSystem;
use Rush\Tool\Schema\Transport\AgentBundler;


final class AgentExportCommand extends CommandAbstract
{
    public function name(): string
    {
        return 'schema agent:export';
    }

    public function description(): string
    {
        return 'Export the standalone HTTP agent';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [
            new Option('path', 'Write agent to this path instead of stdout', true, null, false, 'file', 'p'),
        ];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $script = AgentBundler::build(null, true);
        $path = $input->string('path');

        if ($path === null || $path === '') {
            $output->write($script);

            return ExitCode::Success->value;
        }

        FileSystem::write($path, $script, 0644);
        $output->line('agent written: '. $path);

        return ExitCode::Success->value;
    }
}
