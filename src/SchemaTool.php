<?php declare(strict_types=1);

namespace Rush\Tool\Schema;

use Rush\Contract\CommandInterface;
use Rush\Contract\ToolInterface;
use Rush\Tool\Schema\Command\AgentExportCommand;
use Rush\Tool\Schema\Command\CheckpointCommand;
use Rush\Tool\Schema\Command\CheckpointsCommand;
use Rush\Tool\Schema\Command\DiffCommand;
use Rush\Tool\Schema\Command\InitCommand;
use Rush\Tool\Schema\Command\PushCommand;
use Rush\Tool\Schema\Command\RegressCommand;
use Rush\Tool\Schema\Command\SaveCommand;
use Rush\Tool\Schema\Command\StatusCommand;
use Rush\Tool\Schema\Command\TargetsCommand;
use Rush\Tool\Schema\Command\VerifyCommand;


final class SchemaTool implements ToolInterface
{
    public const VERSION = '0.1.0';


    public function key(): string
    {
        return 'schema';
    }

    public function name(): string
    {
        return 'schema';
    }

    public function description(): string
    {
        return 'Structural database schema versioning tool';
    }

    public function version(): string
    {
        return self::VERSION;
    }

    /** @return array<string, CommandInterface> */
    public function commands(string $projectRoot): array
    {
        return [
            'schema init' => new InitCommand($projectRoot),
            'schema save' => new SaveCommand($projectRoot),
            'schema checkpoint' => new CheckpointCommand($projectRoot),
            'schema regress' => new RegressCommand($projectRoot),
            'schema status' => new StatusCommand($projectRoot),
            'schema diff' => new DiffCommand($projectRoot),
            'schema checkpoints' => new CheckpointsCommand($projectRoot),
            'schema verify' => new VerifyCommand($projectRoot),
            'schema targets' => new TargetsCommand($projectRoot),
            'schema push' => new PushCommand($projectRoot),
            'schema agent:export' => new AgentExportCommand($projectRoot),
        ];
    }

    /** @return list<string> */
    public function install(string $projectRoot): array
    {
        return (new Initializer($projectRoot))->run();
    }
}
