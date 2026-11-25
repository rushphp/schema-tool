<?php declare(strict_types=1);

use Rush\Contract\ExitCode;
use Rush\Tool\Schema\Command\CheckpointCommand;
use Rush\Tool\Schema\Command\CheckpointsCommand;
use Rush\Tool\Schema\Command\DiffCommand;
use Rush\Tool\Schema\Command\InitCommand;
use Rush\Tool\Schema\Command\PushCommand;
use Rush\Tool\Schema\Command\RegressCommand;
use Rush\Tool\Schema\Command\SaveCommand;
use Rush\Tool\Schema\Command\StatusCommand;
use Rush\Tool\Schema\Command\VerifyCommand;
use Rush\Tool\Schema\Exception\UnsupportedObjectsException;
use Rush\Tool\Schema\Paths\SchemaPaths;
use Rush\Tool\Schema\Trace\TraceRepository;
use Tests\SchemaFixtures;


function schemaWorkspaceWithDatabase(string $sql = ''): string
{
    $root = SchemaFixtures::workspace();
    SchemaFixtures::sqlite($root .'/storage/database.sqlite', $sql);

    return $root;
}


function schemaRun(\Rush\Contract\CommandInterface $command, array $tokens = []): array
{
    $fixture = SchemaFixtures::output();
    $code = $command->run(SchemaFixtures::input($command, $tokens), $fixture['output']);

    return ['code' => $code, 'text' => SchemaFixtures::read($fixture['stream'])];
}


function schemaTrace(string $root): TraceRepository
{
    return new TraceRepository(new SchemaPaths($root, 'default'));
}


test('init creates genesis save and checkpoint', function () {
    $root = schemaWorkspaceWithDatabase(<<<'SQL'
CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE);
CREATE VIEW active_users AS SELECT * FROM users;
SQL);
    $run = schemaRun(new InitCommand($root));

    expect($run['code'])->toBe(ExitCode::Success->value)
        ->and($run['text'])->toContain('genesis created');

    $trace = schemaTrace($root);
    $head = $trace->requireSave((string) $trace->head());
    $checkpoint = $trace->latestCheckpoint();

    expect($head->parent)->toBeNull()
        ->and($head->label)->toBe('GENESIS')
        ->and($checkpoint)->not->toBeNull()
        ->and($trace->allSaves())->toHaveCount(1)
        ->and($trace->allCheckpoints())->toHaveCount(1)
        ->and($trace->stateExists($head->toHash))->toBeTrue();
});


test('init refuses an already initialized database', function () {
    $root = schemaWorkspaceWithDatabase('CREATE TABLE users (id INTEGER PRIMARY KEY);');

    schemaRun(new InitCommand($root));
    $run = schemaRun(new InitCommand($root));

    expect($run['code'])->toBe(ExitCode::Failure->value)
        ->and($run['text'])->toContain('already initialized');
});


test('init refuses databases with triggers', function () {
    $root = schemaWorkspaceWithDatabase(<<<'SQL'
CREATE TABLE users (id INTEGER PRIMARY KEY);
CREATE TABLE audit (id INTEGER PRIMARY KEY, user_id INTEGER);
CREATE TRIGGER trg_users_audit AFTER INSERT ON users
BEGIN
    INSERT INTO audit (user_id) VALUES (NEW.id);
END;
SQL);

    expect(fn () => schemaRun(new InitCommand($root)))
        ->toThrow(UnsupportedObjectsException::class, 'trigger trg_users_audit');
});


test('save and checkpoint track structural changes', function () {
    $root = schemaWorkspaceWithDatabase('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT);');
    schemaRun(new InitCommand($root));

    $pdo = new PDO('sqlite:'. $root .'/storage/database.sqlite');
    $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
    $pdo->exec('CREATE INDEX idx_posts_title ON posts(title)');

    $status = schemaRun(new StatusCommand($root));
    expect($status['code'])->toBe(ExitCode::CheckFailed->value)
        ->and($status['text'])->toContain('dirty: yes')
        ->and($status['text'])->toContain('create_table=1');

    $save = schemaRun(new SaveCommand($root), ['--label=add posts']);
    expect($save['code'])->toBe(ExitCode::Success->value)
        ->and($save['text'])->toContain('save created');

    $checkpoint = schemaRun(new CheckpointCommand($root), ['-l', 'v2']);
    expect($checkpoint['code'])->toBe(ExitCode::Success->value)
        ->and($checkpoint['text'])->toContain('checkpoint created');

    $status = schemaRun(new StatusCommand($root));
    expect($status['code'])->toBe(ExitCode::Success->value)
        ->and($status['text'])->toContain('dirty: no')
        ->and($status['text'])->toContain('checkpoints: 2');

    $list = schemaRun(new CheckpointsCommand($root));
    expect($list['code'])->toBe(ExitCode::Success->value)
        ->and($list['text'])->toContain('label=v2');

    $verify = schemaRun(new VerifyCommand($root));
    expect($verify['code'])->toBe(ExitCode::Success->value)
        ->and($verify['text'])->toContain('verify: OK');

    $diff = schemaRun(new DiffCommand($root), ['--from=local', '--to=local', '-m', 'json']);
    expect($diff['code'])->toBe(ExitCode::Success->value)
        ->and($diff['text'])->toContain('"changes"');
});


test('regress creates a forward save that restores an earlier structure', function () {
    $root = schemaWorkspaceWithDatabase('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT);');
    schemaRun(new InitCommand($root));
    $trace = schemaTrace($root);
    $genesis = $trace->requireSave((string) $trace->head());

    $pdo = new PDO('sqlite:'. $root .'/storage/database.sqlite');
    $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY)');
    schemaRun(new SaveCommand($root), ['--checkpoint', '--label=v2']);

    $regress = schemaRun(new RegressCommand($root), ['-t', $genesis->id, '-c', '--allow-destructive', '--yes']);
    expect($regress['code'])->toBe(ExitCode::Success->value)
        ->and($regress['text'])->toContain('regression saved')
        ->and($regress['text'])->toContain('backup:');

    $trace = schemaTrace($root);
    $head = $trace->requireSave((string) $trace->head());

    expect($head->toHash)->toBe($genesis->toHash)
        ->and($trace->checkpointForSave($head->id))->not->toBeNull();

    $verify = schemaRun(new VerifyCommand($root));
    expect($verify['code'])->toBe(ExitCode::Success->value);
});


test('push local is a no-op when already at the checkpoint', function () {
    $root = schemaWorkspaceWithDatabase('CREATE TABLE users (id INTEGER PRIMARY KEY);');
    schemaRun(new InitCommand($root));

    $run = schemaRun(new PushCommand($root), ['-T', 'local']);

    expect($run['code'])->toBe(ExitCode::Success->value)
        ->and($run['text'])->toContain('already at');
});


test('status target reports current state', function () {
    $root = schemaWorkspaceWithDatabase('CREATE TABLE users (id INTEGER PRIMARY KEY);');
    schemaRun(new InitCommand($root));

    $run = schemaRun(new StatusCommand($root), ['-T', 'local', '-j']);

    expect($run['code'])->toBe(ExitCode::Success->value)
        ->and($run['text'])->toContain('"relation": "current"');
});


test('schema tool registers its commands', function () {
    $tool = new \Rush\Tool\Schema\SchemaTool();

    expect(array_keys($tool->commands(sys_get_temp_dir())))->toBe([
        'schema init',
        'schema save',
        'schema checkpoint',
        'schema regress',
        'schema status',
        'schema diff',
        'schema checkpoints',
        'schema verify',
        'schema targets',
        'schema push',
        'schema agent:export',
    ])->and($tool->name())->toBe('schema')
        ->and($tool->version())->toBe('0.1.0');
});
