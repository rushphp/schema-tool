<?php declare(strict_types=1);

use Rush\Contract\ExitCode;
use Rush\Tool\Schema\Command\InitCommand;
use Rush\Tool\Schema\Command\TargetsCommand;
use Tests\SchemaFixtures;


test('targets lists configured targets and last verification', function () {
    $root = SchemaFixtures::workspace();
    SchemaFixtures::sqlite($root .'/storage/database.sqlite', 'CREATE TABLE users (id INTEGER PRIMARY KEY)');

    $config = SchemaFixtures::config('storage/database.sqlite');
    $config['databases']['default']['targets']['development'] = [
        'transport' => 'local',
        'database' => 'storage/development.sqlite',
    ];
    file_put_contents($root .'/.rush/schema/config.json', json_encode($config, JSON_THROW_ON_ERROR));

    SchemaFixtures::run(new InitCommand($root));
    $trace = SchemaFixtures::trace($root);
    $trace->writeTargetRecord('local', [
        'format' => 1,
        'checkpoint' => 'cp_test',
        'verifiedAt' => '2026-01-01T00:00:00+00:00',
    ]);

    $run = SchemaFixtures::run(new TargetsCommand($root));

    expect($run['code'])->toBe(ExitCode::Success->value)
        ->and($run['text'])->toContain('local transport=local')
        ->and($run['text'])->toContain('development transport=local')
        ->and($run['text'])->toContain('last=cp_test')
        ->and($run['text'])->toContain('at=2026-01-01T00:00:00+00:00');
});
