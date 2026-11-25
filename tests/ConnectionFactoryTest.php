<?php declare(strict_types=1);

use Rush\Tool\Schema\Config\DatabaseConfig;
use Rush\Tool\Schema\Config\TargetConfig;
use Rush\Tool\Schema\ConnectionFactory;
use Rush\Tool\Schema\Exception\ConnectionException;
use Rush\Tool\Schema\Exception\TransportException;


test('connection factory resolves absolute and relative sqlite paths', function () {
    $root = sys_get_temp_dir() .'/schema-conn-'. bin2hex(random_bytes(6));
    mkdir($root .'/storage', 0755, true);
    file_put_contents($root .'/storage/database.sqlite', '');
    $factory = new ConnectionFactory($root);
    $database = new DatabaseConfig('default', 'sqlite', null, [
        'local' => new TargetConfig('local', 'local', 'storage/database.sqlite', 'sqlite'),
    ]);

    expect($factory->absolutePath('storage/database.sqlite'))->toBe($root .'/storage/database.sqlite')
        ->and($factory->absolutePath('/tmp/database.sqlite'))->toBe('/tmp/database.sqlite')
        ->and($factory->absolutePath('C:\\tmp\\database.sqlite'))->toBe('C:\\tmp\\database.sqlite');

    $pdo = $factory->connect($database, $database->local(), true);
    expect($pdo)->toBeInstanceOf(PDO::class);
});


test('connection factory rejects missing sqlite and remote transports', function () {
    $root = sys_get_temp_dir() .'/schema-conn-missing-'. bin2hex(random_bytes(6));
    mkdir($root, 0755, true);
    $factory = new ConnectionFactory($root);
    $database = new DatabaseConfig('default', 'sqlite', null, [
        'local' => new TargetConfig('local', 'local', 'missing.sqlite', 'sqlite'),
    ]);
    $remote = new DatabaseConfig('default', 'sqlite', null, [
        'local' => new TargetConfig('local', 'local', 'missing.sqlite', 'sqlite'),
        'prod' => new TargetConfig('prod', 'ssh', 'missing.sqlite', 'sqlite'),
    ]);

    expect(fn () => $factory->connect($database, $database->local(), true))
        ->toThrow(ConnectionException::class, 'sqlite database not found')
        ->and(fn () => $factory->connect($remote, $remote->target('prod')))
        ->toThrow(TransportException::class, 'not implemented yet');
});
