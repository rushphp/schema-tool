<?php declare(strict_types=1);

use Rush\Tool\Schema\Diff\SchemaDiff;
use Rush\Tool\Schema\Driver\MysqlDialect;
use Rush\Tool\Schema\Domain\Column;
use Rush\Tool\Schema\Domain\ForeignKey;
use Rush\Tool\Schema\Domain\Index;
use Rush\Tool\Schema\Domain\SchemaState;
use Rush\Tool\Schema\Domain\Table;
use Rush\Tool\Schema\Domain\View;


test('mysql release generates expected sql', function () {
    $state = new SchemaState(
        [
            'teams' => new Table(
                'teams',
                ['id' => new Column('id', 'int', false, null, 1, false)],
                ['id'],
                [],
                [],
                ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'],
            ),
            'users' => new Table(
                'users',
                [
                    'id' => new Column('id', 'int', false, null, 1, true),
                    'team_id' => new Column('team_id', 'int', true, null, 2, false),
                    'email' => new Column('email', 'varchar(255)', false, "'user@example.com'", 3, false),
                ],
                ['id'],
                ['idx_users_email' => new Index('idx_users_email', ['email'], true, 'explicit', false)],
                ['fk:team_id->teams(id)' => new ForeignKey('fk:team_id->teams(id)', ['team_id'], 'teams', ['id'], 'CASCADE', '', '')],
                ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'],
            ),
        ],
        ['active_users' => new View('active_users', 'CREATE VIEW `active_users` AS SELECT * FROM `users`')],
    );

    $sql = (new MysqlDialect())->release($state);

    expect($sql)->toContain('CREATE TABLE `teams`')
        ->and($sql)->toContain('CREATE TABLE `users`')
        ->and($sql)->toContain('AUTO_INCREMENT')
        ->and($sql)->toContain('PRIMARY KEY (`id`)')
        ->and($sql)->toContain('UNIQUE INDEX `idx_users_email`')
        ->and($sql)->toContain('FOREIGN KEY (`team_id`)')
        ->and($sql)->toContain('COLLATE=utf8mb4_unicode_ci')
        ->and($sql)->toContain('CREATE VIEW `active_users`');
});


test('mysql diff drops and recreates changed tables', function () {
    $from = new SchemaState(
        ['users' => new Table('users', ['id' => new Column('id', 'int', false, null, 1, true)], ['id'], [], [])],
        [],
    );

    $to = new SchemaState(
        ['users' => new Table('users', [
            'id' => new Column('id', 'int', false, null, 1, true),
            'email' => new Column('email', 'varchar(255)', false, null, 2, false),
        ], ['id'], [], [])],
        [],
    );

    $sql = (new MysqlDialect())->sql(SchemaDiff::between($from, $to));

    expect($sql)->toContain('SET FOREIGN_KEY_CHECKS = 0')
        ->and($sql)->toContain('CREATE TABLE `__rush_new_users`')
        ->and($sql)->toContain('RENAME TABLE `__rush_new_users` TO `users`')
        ->and($sql)->toContain('SET FOREIGN_KEY_CHECKS = 1');
});
