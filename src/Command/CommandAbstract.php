<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Command;

use Rush\Contract\CommandInterface;
use Rush\Contract\InputInterface;
use Rush\Contract\Option;
use Rush\Contract\OutputInterface;
use Rush\Tool\Schema\Backup\BackupService;
use Rush\Tool\Schema\Config;
use Rush\Tool\Schema\Config\DatabaseConfig;
use Rush\Tool\Schema\Config\TargetConfig;
use Rush\Tool\Schema\ConnectionFactory;
use Rush\Tool\Schema\Driver\DriverInterface;
use Rush\Tool\Schema\Driver\DriverFactory;
use Rush\Tool\Schema\Exception\SchemaException;
use Rush\Tool\Schema\Exception\TraceException;
use Rush\Tool\Schema\Paths\SchemaPaths;
use Rush\Tool\Schema\Session\SessionFactory;
use Rush\Tool\Schema\Session\SessionInterface;
use Rush\Tool\Schema\Sql\SqlExecutor;
use Rush\Tool\Schema\Trace\Checkpoint;
use Rush\Tool\Schema\Trace\Save;
use Rush\Tool\Schema\Trace\TraceRepository;


abstract class CommandAbstract implements CommandInterface
{
    private ?Config $config = null;

    private ?ConnectionFactory $connections = null;

    private ?SqlExecutor $executor = null;

    private ?SessionFactory $sessions = null;


    public function __construct(
        protected readonly string $projectRoot,
    ) {
    }


    public function usage(): string
    {
        $parts = ['rush', $this->name()];

        foreach ($this->options() as $option) {
            $label = '--'. $option->name;

            if ($option->takesValue) {
                $label .= '=<'. $option->valueName .'>';
            }

            $parts[] = $option->required ? $label : '['. $label .']';
        }

        return implode(' ', $parts);
    }


    /** @return list<Option> */
    public function options(): array
    {
        return $this->commandOptions();
    }


    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [];
    }


    protected function databaseOption(): Option
    {
        return new Option('database', 'Database name', true, null, false, 'name', 'd');
    }


    protected function config(): Config
    {
        return $this->config ??= Config::load($this->projectRoot);
    }


    protected function database(InputInterface $input): DatabaseConfig
    {
        return $this->config()->database($input->string('database'));
    }


    protected function paths(DatabaseConfig $database): SchemaPaths
    {
        return new SchemaPaths($this->projectRoot, $database->name);
    }


    protected function trace(DatabaseConfig $database): TraceRepository
    {
        return new TraceRepository($this->paths($database));
    }


    protected function connections(): ConnectionFactory
    {
        return $this->connections ??= new ConnectionFactory($this->projectRoot);
    }


    protected function executor(): SqlExecutor
    {
        return $this->executor ??= new SqlExecutor();
    }


    protected function sessions(): SessionFactory
    {
        return $this->sessions ??= new SessionFactory($this->projectRoot, $this->connections());
    }


    protected function session(
        DatabaseConfig $database,
        TargetConfig $target,
        bool $mustExist = false,
        bool $forWrite = false,
    ): SessionInterface {
        return $this->sessions()->create($database, $target, $mustExist, $forWrite);
    }


    protected function driver(DatabaseConfig $database): DriverInterface
    {
        return DriverFactory::make($database->driver);
    }


    protected function backup(DatabaseConfig $database): BackupService
    {
        return new BackupService($this->paths($database), $this->connections());
    }


    protected function localTarget(DatabaseConfig $database): TargetConfig
    {
        return $database->local();
    }


    protected function requireInitialized(TraceRepository $trace): void
    {
        if ( ! $trace->initialized()) {
            throw new TraceException('schema: database is not initialized; run schema init');
        }
    }


    protected function latestCheckpoint(TraceRepository $trace): Checkpoint
    {
        $checkpoint = $trace->latestCheckpoint();

        if ($checkpoint === null) {
            throw new TraceException('schema: no checkpoint found; run schema checkpoint');
        }

        return $checkpoint;
    }


    protected function checkpoint(TraceRepository $trace, ?string $id): Checkpoint
    {
        if ($id === null || $id === '') {
            return $this->latestCheckpoint($trace);
        }

        return $trace->requireCheckpoint($id);
    }


    protected function resolveCheckpointOrSave(TraceRepository $trace, string $id): Checkpoint|Save
    {
        $checkpoint = $trace->checkpoint($id);

        if ($checkpoint !== null) {
            return $checkpoint;
        }

        return $trace->requireSave($id);
    }


    protected function out(OutputInterface $output, DatabaseConfig $database, string $message): void
    {
        $output->line('['. $database->name .'] '. $message);
    }


    protected function confirmDestructive(InputInterface $input, OutputInterface $output, string $message): void
    {
        if ( ! $input->bool('allow-destructive')) {
            throw new SchemaException($message .' use --allow-destructive to proceed');
        }

        if ($input->bool('yes')) {
            return;
        }

        if ( ! $this->isInteractive()) {
            throw new SchemaException($message .' use --yes in non-interactive mode');
        }

        $output->warning($message .' type "yes" to continue: ');
        $answer = fgets(STDIN);

        if (is_string($answer) && trim($answer) === 'yes') {
            return;
        }

        throw new SchemaException('schema: destructive operation cancelled');
    }


    private function isInteractive(): bool
    {
        return function_exists('posix_isatty') && posix_isatty(STDIN);
    }
}
