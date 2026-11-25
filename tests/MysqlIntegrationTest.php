<?php declare(strict_types=1);

use Rush\Contract\ExitCode;
use Rush\Tool\Schema\Command\CheckpointCommand;
use Rush\Tool\Schema\Command\InitCommand;
use Rush\Tool\Schema\Command\RegressCommand;
use Rush\Tool\Schema\Command\SaveCommand;
use Rush\Tool\Schema\Command\StatusCommand;
use Rush\Tool\Schema\Command\VerifyCommand;
use Tests\SchemaFixtures;


function mysqlEnvironment(): ?array
{
    $host = getenv('SCHEMA_MYSQL_HOST');

    if ($host === false || $host === '') {
        return null;
    }

    return [
        'host' => $host,
        'port' => (int) (getenv('SCHEMA_MYSQL_PORT') ?: 3306),
        'user' => (string) (getenv('SCHEMA_MYSQL_USER') ?: 'root'),
        'password' => (string) (getenv('SCHEMA_MYSQL_PASSWORD') ?: ''),
    ];
}


function mysqlWorkspace(array $env): array
{
    $database = 'schema_it_'. bin2hex(random_bytes(4));
    $server = new PDO(
        'mysql:host='. $env['host'] .';port='. $env['port'] .';charset=utf8mb4',
        $env['user'],
        $env['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $server->exec('CREATE DATABASE `'. $database .'`');
    $pdo = new PDO(
        'mysql:host='. $env['host'] .';port='. $env['port'] .';dbname='. $database .';charset=utf8mb4',
        $env['user'],
        $env['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $root = sys_get_temp_dir() .'/schema-mysql-'. bin2hex(random_bytes(6));
    mkdir($root .'/.rush/schema', 0755, true);
    file_put_contents($root .'/.rush/schema/config.json', json_encode([
        'databases' => [
            'default' => [
                'driver' => 'mysql',
                'targets' => [
                    'local' => [
                        'transport' => 'local',
                        'database' => $database,
                        'host' => $env['host'],
                        'port' => $env['port'],
                        'user' => $env['user'],
                        'password' => $env['password'],
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    return ['root' => $root, 'server' => $server, 'database' => $database, 'pdo' => $pdo];
}


test('mysql workflow init, save, checkpoint, regress and verify', function () {
    $env = mysqlEnvironment();

    if ($env === null) {
        $this->markTestSkipped('SCHEMA_MYSQL_HOST is not set');
    }

    $workspace = mysqlWorkspace($env);

    try {
        $root = $workspace['root'];
        $pdo = $workspace['pdo'];
        $pdo->exec('CREATE TABLE users (id INT NOT NULL AUTO_INCREMENT, email VARCHAR(255) NOT NULL, PRIMARY KEY (id), UNIQUE KEY uq_users_email (email)) ENGINE=InnoDB');

        $init = SchemaFixtures::run(new InitCommand($root));
        expect($init['code'])->toBe(ExitCode::Success->value);

        $pdo->exec('CREATE TABLE posts (id INT NOT NULL AUTO_INCREMENT, title VARCHAR(255) NOT NULL, PRIMARY KEY (id), KEY idx_posts_title (title)) ENGINE=InnoDB');

        $save = SchemaFixtures::run(new SaveCommand($root), ['--label=add posts']);
        expect($save['code'])->toBe(ExitCode::Success->value);

        $checkpoint = SchemaFixtures::run(new CheckpointCommand($root), ['-l', 'v2']);
        expect($checkpoint['code'])->toBe(ExitCode::Success->value);

        $status = SchemaFixtures::run(new StatusCommand($root));
        expect($status['code'])->toBe(ExitCode::Success->value);

        $genesis = SchemaFixtures::trace($root)->chain()[0];

        $regress = SchemaFixtures::run(new RegressCommand($root), [
            '-t', $genesis->id,
            '-c',
            '--allow-destructive',
            '--yes',
        ]);
        expect($regress['code'])->toBe(ExitCode::Success->value);

        $verify = SchemaFixtures::run(new VerifyCommand($root));
        expect($verify['code'])->toBe(ExitCode::Success->value);
    } finally {
        $workspace['server']->exec('DROP DATABASE IF EXISTS `'. $workspace['database'] .'`');
    }
});
