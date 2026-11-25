<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Sql;

use PDO;
use Rush\Tool\Schema\Exception\DriverException;


final class SqlExecutor
{
    public function apply(PDO $pdo, string $sql, string $driver = 'sqlite'): void
    {
        if (trim($sql) === '') {
            return;
        }

        if ($driver === 'sqlite') {
            $this->sqlite($pdo, $sql);

            return;
        }

        $pdo->exec($sql);
    }


    private function sqlite(PDO $pdo, string $sql): void
    {
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->beginTransaction();

        try {
            $pdo->exec($sql);
            $this->assertForeignKeys($pdo);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }


    private function assertForeignKeys(PDO $pdo): void
    {
        $statement = $pdo->query('PRAGMA foreign_key_check');

        if ($statement === false) {
            return;
        }

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        if ($rows !== []) {
            throw new DriverException('schema: migration would violate foreign keys: '. json_encode($rows));
        }
    }
}
