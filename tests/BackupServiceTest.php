<?php declare(strict_types=1);

use Rush\Tool\Schema\Backup\BackupService;
use Rush\Tool\Schema\ConnectionFactory;
use Rush\Tool\Schema\Exception\SchemaException;
use Rush\Tool\Schema\Paths\SchemaPaths;


final class BackupServiceTestable extends BackupService
{
    /**
     * @param list<string> $binaries
     */
    public function __construct(
        private readonly array $binaries,
        private readonly bool $columnStatistics,
        SchemaPaths $paths,
        ConnectionFactory $connections,
    ) {
        parent::__construct($paths, $connections);
    }


    protected function binaryExists(string $binary): bool
    {
        return in_array($binary, $this->binaries, true);
    }


    protected function supportsColumnStatistics(string $binary): bool
    {
        return $this->columnStatistics;
    }


    public function resolvedBinary(): string
    {
        return $this->dumpBinary();
    }
}


function backupServiceTestable(array $binaries, bool $columnStatistics = false): BackupServiceTestable
{
    return new BackupServiceTestable(
        $binaries,
        $columnStatistics,
        new SchemaPaths(sys_get_temp_dir(), 'default'),
        new ConnectionFactory(sys_get_temp_dir()),
    );
}


test('prefers mariadb-dump over mysqldump', function () {
    expect(backupServiceTestable(['mariadb-dump', 'mysqldump'])->resolvedBinary())
        ->toBe('mariadb-dump');
});


test('falls back to mysqldump when mariadb-dump is missing', function () {
    expect(backupServiceTestable(['mysqldump'])->resolvedBinary())
        ->toBe('mysqldump');
});


test('fails when no dump client is available', function () {
    expect(fn () => backupServiceTestable([])->resolvedBinary())
        ->toThrow(SchemaException::class, 'no compatible dump client');
});
