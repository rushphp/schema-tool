<?php declare(strict_types=1);

use Rush\Testing\Conformance\ToolConformance;
use Rush\Tool\Schema\SchemaTool;
use Rush\Support\FileSystem;


test('schema tool satisfies the rush conformance rules', function (): void {
    $root = sys_get_temp_dir() .'/schema-conformance-'. bin2hex(random_bytes(6));
    FileSystem::ensureDirectory($root);

    try {
        ToolConformance::assert(new SchemaTool(), $root);
        expect(true)->toBeTrue();
    } finally {
        FileSystem::removeDir($root);
    }
});


test('schema tool exposes its commands scoped to its key', function (): void {
    $tool = new SchemaTool();
    $commands = $tool->commands(sys_get_temp_dir());

    expect($tool->key())->toBe('schema')
        ->and(array_keys($commands))->toContain('schema init', 'schema status')
        ->and($commands['schema status']->name())->toBe('schema status');
});
