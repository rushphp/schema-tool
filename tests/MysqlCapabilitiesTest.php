<?php declare(strict_types=1);

use Rush\Tool\Schema\Driver\MysqlCapabilities;


test('detects mysql and mariadb capabilities', function () {
    $mysql = MysqlCapabilities::fromVersion('8.4.0');
    $maria = MysqlCapabilities::fromVersion('11.8.9-MariaDB');

    expect($mysql->flavor)->toBe('mysql')
        ->and($maria->flavor)->toBe('mariadb')
        ->and($mysql->supports('json_type'))->toBeTrue()
        ->and($maria->supports('json_type'))->toBeFalse()
        ->and($mysql->supports('enforced_check'))->toBeTrue()
        ->and($maria->supports('enforced_check'))->toBeFalse()
        ->and($mysql->supports('check_constraints'))->toBeTrue()
        ->and($maria->supports('check_constraints'))->toBeTrue()
        ->and($mysql->supports('desc_indexes'))->toBeTrue()
        ->and($maria->supports('desc_indexes'))->toBeTrue()
        ->and($mysql->supports('partitions'))->toBeFalse();
});


test('mariadb 10.11 baseline supports check and descending indexes', function () {
    $maria = MysqlCapabilities::fromVersion('10.11.14-MariaDB');

    expect($maria->supports('check_constraints'))->toBeTrue()
        ->and($maria->supports('desc_indexes'))->toBeTrue()
        ->and($maria->supports('json_expression_default'))->toBeFalse();
});
