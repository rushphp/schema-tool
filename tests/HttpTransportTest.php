<?php declare(strict_types=1);

use Rush\Contract\ExitCode;
use Rush\Tool\Schema\Command\InitCommand;
use Rush\Tool\Schema\Command\PushCommand;
use Rush\Tool\Schema\Command\SaveCommand;
use Rush\Tool\Schema\Command\StatusCommand;
use Rush\Tool\Schema\Transport\AgentBundler;
use Tests\SchemaFixtures;


test('http transport checks status and pushes a sqlite target', function () {
    $root = SchemaFixtures::workspace();
    $local = $root .'/storage/database.sqlite';
    $targetDatabase = $root .'/storage/target.sqlite';
    SchemaFixtures::sqlite($local, 'CREATE TABLE users (id INTEGER PRIMARY KEY)');
    copy($local, $targetDatabase);

    SchemaFixtures::run(new InitCommand($root));

    $pdo = new PDO('sqlite:'. $local);
    $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
    SchemaFixtures::run(new SaveCommand($root), ['--checkpoint', '-l', 'v2']);

    $docroot = $root .'/public';
    mkdir($docroot, 0755, true);
    file_put_contents($docroot .'/agent.php', AgentBundler::build(null, true));
    file_put_contents($docroot .'/agent.config.json', json_encode(['token' => 'test-token'], JSON_THROW_ON_ERROR));

    $port = random_int(20000, 40000);
    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:'. $port, '-t', $docroot],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );

    expect(is_resource($process))->toBeTrue();

    try {
        $ready = false;

        for ($i = 0; $i < 50; $i++) {
            set_error_handler(static fn (): bool => true);

            try {
                $socket = fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.2);
            } finally {
                restore_error_handler();
            }

            if (is_resource($socket)) {
                fclose($socket);
                $ready = true;
                break;
            }

            usleep(100000);
        }

        expect($ready)->toBeTrue('http agent did not start');

        file_put_contents($root .'/.rush/schema/tokens-production', 'test-token');

        $config = SchemaFixtures::config('storage/database.sqlite');
        $config['databases']['default']['targets']['production'] = [
            'transport' => 'http',
            'url' => 'http://127.0.0.1:'. $port .'/agent.php',
            'database' => $targetDatabase,
            'token_file' => '.rush/schema/tokens-production',
            'secure' => false,
        ];
        file_put_contents($root .'/.rush/schema/config.json', json_encode($config, JSON_THROW_ON_ERROR));

        $status = SchemaFixtures::run(new StatusCommand($root), ['-T', 'production', '-j']);
        expect($status['code'])->toBe(ExitCode::CheckFailed->value)
            ->and($status['text'])->toContain('"relation": "behind"');

        $push = SchemaFixtures::run(new PushCommand($root), ['-T', 'production']);
        expect($push['code'])->toBe(ExitCode::Success->value)
            ->and($push['text'])->toContain('push complete');

        $status = SchemaFixtures::run(new StatusCommand($root), ['-T', 'production']);
        expect($status['code'])->toBe(ExitCode::Success->value)
            ->and($status['text'])->toContain('relation: current');
    } finally {
        proc_terminate($process);
        proc_close($process);
    }
});
