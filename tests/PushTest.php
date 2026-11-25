<?php declare(strict_types=1);

use Rush\Contract\ExitCode;
use Rush\Tool\Schema\Command\CheckpointsCommand;
use Rush\Tool\Schema\Command\InitCommand;
use Rush\Tool\Schema\Command\PushCommand;
use Rush\Tool\Schema\Command\SaveCommand;
use Rush\Tool\Schema\Command\StatusCommand;
use Tests\SchemaFixtures;


test('push forwards a behind local target to the latest checkpoint', function () {
    $root = SchemaFixtures::workspace();
    $database = $root .'/storage/database.sqlite';
    SchemaFixtures::sqlite($database, 'CREATE TABLE users (id INTEGER PRIMARY KEY)');
    SchemaFixtures::run(new InitCommand($root));

    $before = $root .'/storage/before.sqlite';
    copy($database, $before);

    $pdo = new PDO('sqlite:'. $database);
    $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
    SchemaFixtures::run(new SaveCommand($root), ['--checkpoint', '-l', 'v2']);

    copy($before, $database);

    $push = SchemaFixtures::run(new PushCommand($root), ['-T', 'local']);

    expect($push['code'])->toBe(ExitCode::Success->value)
        ->and($push['text'])->toContain('push complete')
        ->and($push['text'])->toContain('backup:');

    $status = SchemaFixtures::run(new StatusCommand($root));
    expect($status['code'])->toBe(ExitCode::Success->value)
        ->and($status['text'])->toContain('dirty: no');

    $record = SchemaFixtures::trace($root)->targetRecord('local');
    expect($record)->not->toBeNull()
        ->and($record['checkpoint'] ?? null)->toBeString();
});


test('checkpoints show prints checkpoint details', function () {
    $root = SchemaFixtures::workspace();
    SchemaFixtures::sqlite($root .'/storage/database.sqlite', 'CREATE TABLE users (id INTEGER PRIMARY KEY)');
    SchemaFixtures::run(new InitCommand($root));

    $checkpoint = SchemaFixtures::trace($root)->latestCheckpoint();
    expect($checkpoint)->not->toBeNull();

    $run = SchemaFixtures::run(new CheckpointsCommand($root), ['-s', (string) $checkpoint?->id]);

    expect($run['code'])->toBe(ExitCode::Success->value)
        ->and($run['text'])->toContain('checkpoint: '. $checkpoint?->id)
        ->and($run['text'])->toContain('artifact release:');
});
