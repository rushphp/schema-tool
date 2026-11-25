<?php declare(strict_types=1);

use Rush\Tool\Schema\Transport\AgentConfig;


function agentConfigFile(array $data): string
{
    $file = sys_get_temp_dir() .'/schema-agent-config-'. bin2hex(random_bytes(6)) .'.json';
    file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR));

    return $file;
}


test('agent config resolves mysql and sqlite connections', function () {
    $file = agentConfigFile([
        'format' => 1,
        'token' => 'secret',
        'databases' => [
            'app' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3307,
                'database' => 'app',
                'user' => 'schema',
                'password' => 'pass',
            ],
            'analytics' => [
                'driver' => 'sqlite',
                'path' => '/srv/analytics.sqlite',
            ],
        ],
    ]);

    $config = AgentConfig::fromPath($file);
    $mysql = $config->connection('app');
    $sqlite = $config->connection('analytics');

    expect($config->token())->toBe('secret')
        ->and($mysql['dsn'])->toContain('dbname=app')
        ->and($mysql['user'])->toBe('schema')
        ->and($sqlite['dsn'])->toBe('sqlite:/srv/analytics.sqlite')
        ->and($sqlite['database'])->toBe('/srv/analytics.sqlite');
});


test('agent config loads path from environment', function () {
    $file = agentConfigFile([
        'format' => 1,
        'token' => 'secret',
        'databases' => [
            'app' => ['driver' => 'sqlite', 'path' => '/tmp/app.sqlite'],
        ],
    ]);
    putenv('RUSH_SCHEMA_AGENT_CONFIG='. $file);

    try {
        expect(AgentConfig::fromEnvironment()->token())->toBe('secret');
    } finally {
        putenv('RUSH_SCHEMA_AGENT_CONFIG');
    }
});


test('agent config rejects missing environment, invalid json and missing database', function () {
    putenv('RUSH_SCHEMA_AGENT_CONFIG');

    expect(fn () => AgentConfig::fromEnvironment())
        ->toThrow(RuntimeException::class, 'RUSH_SCHEMA_AGENT_CONFIG is not set');

    $invalid = sys_get_temp_dir() .'/schema-agent-invalid-'. bin2hex(random_bytes(6)) .'.json';
    file_put_contents($invalid, '{invalid');

    expect(fn () => AgentConfig::fromPath($invalid))
        ->toThrow(RuntimeException::class, 'not valid JSON');

    $file = agentConfigFile([
        'format' => 1,
        'token' => 'secret',
        'databases' => ['app' => ['driver' => 'sqlite', 'path' => '/tmp/app.sqlite']],
    ]);

    expect(fn () => AgentConfig::fromPath($file)->connection('missing'))
        ->toThrow(RuntimeException::class, "no database 'missing'");
});
