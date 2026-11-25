<?php declare(strict_types=1);

use Rush\Tool\Schema\Canonical\SchemaHasher;
use Rush\Tool\Schema\Diff\SchemaDiff;
use Rush\Tool\Schema\Driver\SqliteDialect;
use Rush\Tool\Schema\Driver\SqliteDriver;
use Rush\Tool\Schema\Sql\SqlExecutor;
use Tests\SchemaFixtures;


test('sqlite dialect round-trips structural changes', function () {
    $base = SchemaFixtures::sqlite(
        sys_get_temp_dir() .'/schema-base-'. bin2hex(random_bytes(6)) .'.sqlite',
        <<<'SQL'
CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT NOT NULL);
CREATE TABLE users (id INTEGER PRIMARY KEY, team_id INTEGER, email TEXT NOT NULL, FOREIGN KEY (team_id) REFERENCES teams(id));
CREATE VIEW active_users AS SELECT * FROM users WHERE email IS NOT NULL;
SQL,
    );

    $target = SchemaFixtures::sqlite(
        sys_get_temp_dir() .'/schema-target-'. bin2hex(random_bytes(6)) .'.sqlite',
        <<<'SQL'
CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT NOT NULL, slug TEXT);
CREATE TABLE users (id INTEGER PRIMARY KEY, team_id INTEGER, email TEXT NOT NULL, score INTEGER DEFAULT 0, FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE);
CREATE UNIQUE INDEX idx_users_email ON users(email);
CREATE VIEW active_users AS SELECT * FROM users WHERE score > 0;
SQL,
    );

    $driver = new SqliteDriver();
    $from = $driver->inspect($base);
    $to = $driver->inspect($target);
    $diff = SchemaDiff::between($from, $to);

    expect($diff->destructive)->toBeFalse();

    $dialect = new SqliteDialect();
    $up = $dialect->sql($diff);
    $down = $dialect->sql(SchemaDiff::between($to, $from));

    $scratch = new PDO('sqlite::memory:');
    $scratch->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $executor = new SqlExecutor();
    $executor->apply($scratch, $dialect->release($from));

    $executor->apply($scratch, $up);
    expect((new SchemaHasher())->hash($driver->inspect($scratch)))->toBe((new SchemaHasher())->hash($to));

    $executor->apply($scratch, $down);
    expect((new SchemaHasher())->hash($driver->inspect($scratch)))->toBe((new SchemaHasher())->hash($from));
});


test('sqlite diff marks destructive changes', function () {
    $from = SchemaFixtures::sqlite(
        sys_get_temp_dir() .'/schema-destructive-from-'. bin2hex(random_bytes(6)) .'.sqlite',
        'CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT);',
    );

    $to = SchemaFixtures::sqlite(
        sys_get_temp_dir() .'/schema-destructive-to-'. bin2hex(random_bytes(6)) .'.sqlite',
        'CREATE TABLE users (id INTEGER PRIMARY KEY);',
    );

    $driver = new SqliteDriver();
    $diff = SchemaDiff::between($driver->inspect($from), $driver->inspect($to));

    expect($diff->destructive)->toBeTrue();
});
