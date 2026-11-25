<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Command;

use Rush\Contract\ExitCode;
use Rush\Contract\InputInterface;
use Rush\Contract\Option;
use Rush\Contract\OutputInterface;
use Rush\Tool\Schema\Config\DatabaseConfig;
use Rush\Tool\Schema\Diff\SchemaDiff;
use Rush\Tool\Schema\Domain\SchemaState;
use Rush\Tool\Schema\Exception\SchemaException;
use Rush\Tool\Schema\Trace\TraceRepository;


final class DiffCommand extends CommandAbstract
{
    public function name(): string
    {
        return 'schema diff';
    }

    public function description(): string
    {
        return 'Show the difference between saves, checkpoints, local or target';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [
            $this->databaseOption(),
            new Option('from', 'Source save, checkpoint, local or target', true, null, false, 'id', 'f'),
            new Option('to', 'Destination save, checkpoint, local or target', true, null, false, 'id', 't'),
            new Option('target', 'Target name', true, null, false, 'name', 'T'),
            new Option('format', 'Output format', true, 'text', false, 'mode', 'm'),
        ];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $database = $this->database($input);
        $trace = $this->trace($database);
        $this->requireInitialized($trace);

        $fromId = $input->string('from');
        $toId = $input->string('to');

        if ($fromId === null || $fromId === '') {
            $fromId = $this->latestCheckpoint($trace)->id;
        }

        if ($toId === null || $toId === '') {
            $toId = 'local';
        }

        $from = $this->side($input, $database, $trace, $fromId);
        $to = $this->side($input, $database, $trace, $toId);
        $diff = SchemaDiff::between($from['state'], $to['state']);
        $dialect = $this->driver($database)->dialect();
        $sql = $dialect->sql($diff);
        $format = strtolower((string) $input->string('format', 'text'));

        if ($format === 'sql') {
            $output->write($sql);

            return ExitCode::Success->value;
        }

        if ($format === 'json') {
            $output->line(json_encode([
                'database' => $database->name,
                'from' => $from['label'],
                'to' => $to['label'],
                'changes' => $diff->summary(),
                'destructive' => $diff->destructive,
                'sql' => $sql,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return ExitCode::Success->value;
        }

        $this->out($output, $database, 'from: '. $from['label']);
        $this->out($output, $database, 'to: '. $to['label']);
        $this->out($output, $database, 'changes: '. $this->changes($diff));
        $this->out($output, $database, 'destructive: '. ($diff->destructive ? 'yes' : 'no'));

        if (trim($sql) !== '') {
            $output->line();
            $output->write($sql);
        }

        return ExitCode::Success->value;
    }


    /**
     * @return array{state: SchemaState, label: string}
     */
    private function side(
        InputInterface $input,
        DatabaseConfig $database,
        TraceRepository $trace,
        string $id,
    ): array {
        if ($id === 'local') {
            $session = $this->session($database, $database->local(), true);

            return ['state' => $session->inspect(), 'label' => 'local'];
        }

        if ($id === 'target') {
            $name = $input->string('target');

            if ($name === null || $name === '') {
                throw new SchemaException('schema: --from=target/--to=target requires --target');
            }

            $session = $this->session($database, $database->target($name), true);

            return ['state' => $session->inspect(), 'label' => 'target:'. $name];
        }

        $checkpoint = $trace->checkpoint($id);

        if ($checkpoint !== null) {
            return [
                'state' => $trace->state($checkpoint->schemaHash),
                'label' => $checkpoint->id .' (save '. $checkpoint->save .')',
            ];
        }

        $save = $trace->requireSave($id);

        return [
            'state' => $trace->state($save->toHash),
            'label' => $save->id,
        ];
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
}
