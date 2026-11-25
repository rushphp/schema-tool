<?php declare(strict_types=1);

use Rush\Tool\Schema\Domain\ForeignKey;
use Rush\Tool\Schema\Domain\Index;
use Rush\Tool\Schema\Domain\View;


test('domain value objects survive array round trips', function () {
    $foreignKey = ForeignKey::fromArray('fk', [
        'columns' => ['team_id'],
        'referencedTable' => 'teams',
        'referencedColumns' => ['id'],
        'onDelete' => 'CASCADE',
        'onUpdate' => '',
        'match' => '',
    ]);
    $index = Index::fromArray('idx', [
        'name' => 'idx_users_email',
        'columns' => ['email'],
        'unique' => true,
    ]);
    $view = View::fromArray('active', ['sql' => 'CREATE VIEW active AS SELECT 1']);

    expect($foreignKey->toArray()['columns'])->toBe(['team_id'])
        ->and($foreignKey->toArray()['matched'] ?? null)->toBeNull()
        ->and($index->toArray()['unique'])->toBeTrue()
        ->and($index->toArray()['source'])->toBe('explicit')
        ->and($view->toArray()['sql'])->toContain('CREATE VIEW');
});
