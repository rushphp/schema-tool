<?php declare(strict_types=1);

use Rush\Tool\Schema\Initializer;


test('initializer creates local layout and config', function () {
    $root = sys_get_temp_dir() .'/schema-init-'. bin2hex(random_bytes(6));
    mkdir($root, 0755, true);
    $initializer = new Initializer($root);

    $first = $initializer->run();
    $second = $initializer->run();

    expect(is_dir($root .'/.rush/schema'))->toBeTrue()
        ->and(is_dir($root .'/.rush/schema/ssh'))->toBeTrue()
        ->and(is_dir($root .'/.rush/schema/tokens'))->toBeTrue()
        ->and(is_file($root .'/.rush/schema/config.json'))->toBeTrue()
        ->and(implode("\n", $first))->toContain('created .rush/schema/config.json')
        ->and(implode("\n", $second))->toContain('exists .rush/schema/config.json');
});
