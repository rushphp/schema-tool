<?php declare(strict_types=1);

use Rush\Contract\ExitCode;
use Rush\Tool\Schema\Command\AgentExportCommand;
use Rush\Tool\Schema\Transport\AgentBundler;
use Tests\SchemaFixtures;


function agentExecute(string $script): string
{
    $file = sys_get_temp_dir() .'/schema-agent-'. bin2hex(random_bytes(6)) .'.php';
    file_put_contents($file, $script);

    $process = proc_open(
        [PHP_BINARY, '-l', $file],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    expect(is_resource($process))->toBeTrue();
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    expect($code)->toBe(0, (string) $stderr);

    return $file;
}


test('agent bundle is valid php and can inspect sqlite', function () {
    $db = sys_get_temp_dir() .'/schema-agent-db-'. bin2hex(random_bytes(6)) .'.sqlite';
    $pdo = new PDO('sqlite:'. $db);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');

    $payload = [
        'action' => 'inspect',
        'connection' => [
            'driver' => 'sqlite',
            'dsn' => 'sqlite:'. $db,
            'database' => $db,
            'user' => null,
            'password' => null,
        ],
    ];

    $file = agentExecute(AgentBundler::build($payload));

    $process = proc_open(
        [PHP_BINARY, $file],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    expect(is_resource($process))->toBeTrue();
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $data = json_decode((string) $stdout, true);

    expect($data['ok'] ?? false)->toBeTrue()
        ->and($data['state']['tables'] ?? [])->toHaveKey('users');
});


test('agent export writes a standalone http agent', function () {
    $path = sys_get_temp_dir() .'/schema-agent-http-'. bin2hex(random_bytes(6)) .'.php';
    $run = SchemaFixtures::output();
    $command = new AgentExportCommand(sys_get_temp_dir());

    $code = $command->run(SchemaFixtures::input($command, ['-p', $path]), $run['output']);

    expect($code)->toBe(ExitCode::Success->value)
        ->and(is_file($path))->toBeTrue()
        ->and(filesize($path))->toBeGreaterThan(1000);
});
