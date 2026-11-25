<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Transport;

use Rush\Tool\Schema\Exception\TransportException;


final class AgentBundler
{
    /** @var list<string> */
    private const FILES = [
        'Exception/SchemaException.php',
        'Exception/DriverException.php',
        'Exception/UnsupportedObjectsException.php',
        'Domain/Column.php',
        'Domain/ForeignKey.php',
        'Domain/Index.php',
        'Domain/SchemaState.php',
        'Domain/Table.php',
        'Domain/View.php',
        'Canonical/CanonicalJson.php',
        'Canonical/SchemaHasher.php',
        'Diff/DiffOperation.php',
        'Diff/SchemaDiff.php',
        'Driver/DriverInterface.php',
        'Driver/SqlDialectInterface.php',
        'Driver/SqliteDriver.php',
        'Driver/SqliteDialect.php',
        'Driver/MysqlDriver.php',
        'Driver/MysqlDialect.php',
        'Driver/MysqlCapabilities.php',
        'Sql/SqlExecutor.php',
        'Transport/AgentConfig.php',
    ];


    /**
     * @param array<string, mixed>|null $payload
     */
    public static function build(?array $payload = null, bool $http = false): string
    {
        $base = dirname(__DIR__);
        $parts = ["<?php declare(strict_types=1);\n"];

        foreach (self::FILES as $file) {
            $path = $base .'/'. $file;
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new TransportException("schema: could not read agent source {$file}");
            }

            $parts[] = self::stripHeader($contents);
        }

        $embedded = $payload === null ? 'null' : var_export(json_encode($payload, JSON_THROW_ON_ERROR), true);
        $parts[] = self::bootstrap($embedded, $http);

        return implode("\n", $parts);
    }


    private static function stripHeader(string $contents): string
    {
        $contents = preg_replace('/\A<\?php\s+declare\(strict_types=1\);\s*/', '', $contents) ?? $contents;
        $contents = preg_replace('/\A<\?php\s*/', '', $contents) ?? $contents;
        $contents = ltrim($contents);

        if (preg_match('/\Anamespace\s+([^;]+);/', $contents, $matches) === 1) {
            $contents = preg_replace('/\Anamespace\s+[^;]+;/', 'namespace '. $matches[1] .' {', $contents, 1) ?? $contents;
            $contents = rtrim($contents) ."\n}\n";
        } else {
            $contents = rtrim($contents) ."\n";
        }

        return $contents;
    }


    private static function bootstrap(string $embedded, bool $http): string
    {
        $httpFlag = $http ? 'true' : 'false';
        $input = $http
            ? <<<'PHP'
$raw = (string) file_get_contents('php://input');
PHP
            : <<<'PHP'
$raw = stream_get_contents(STDIN);
PHP;

        $payload = $embedded === 'null' ? '' : '$raw = '. $embedded .';';

        return <<<PHP

namespace {
    {$input}
    {$payload}

    try {
        if ({$httpFlag}) {
            \$config = \Rush\Tool\Schema\Transport\AgentConfig::fromEnvironment();
            \$header = \$_SERVER['HTTP_AUTHORIZATION'] ?? '';
            \$provided = str_starts_with(\$header, 'Bearer ') ? substr(\$header, 7) : '';
            if (\$provided === '' || ! hash_equals(\$config->token(), \$provided)) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'forbidden']);
                return;
            }
        }

        if (!is_string(\$raw) || trim(\$raw) === '') {
            throw new \RuntimeException('missing payload');
        }
        \$payload = json_decode(\$raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array(\$payload)) {
            throw new \RuntimeException('payload must be an object');
        }

        if ({$httpFlag}) {
            if (array_key_exists('connection', \$payload)) {
                throw new \RuntimeException('request connections are disabled');
            }

            \$databaseKey = \$payload['database'] ?? null;

            if (!is_string(\$databaseKey) || \$databaseKey === '') {
                throw new \RuntimeException('missing database');
            }

            \$connection = \$config->connection(\$databaseKey);
        } else {
            \$connection = \$payload['connection'] ?? null;
            if (!is_array(\$connection)) {
                throw new \RuntimeException('missing connection');
            }
        }

        \$driverName = \$connection['driver'] ?? null;
        \$dsn = \$connection['dsn'] ?? null;
        if (!is_string(\$driverName) || !is_string(\$dsn)) {
            throw new \RuntimeException('invalid connection');
        }
        if (\$driverName === 'mysql' && ! extension_loaded('pdo_mysql')) {
            throw new \RuntimeException('ext-pdo_mysql is required');
        }
        \$options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION];
        if (\$driverName === 'mysql') {
            \$options[\PDO::MYSQL_ATTR_MULTI_STATEMENTS] = true;
        }
        \$pdo = new \PDO(\$dsn, \$connection['user'] ?? null, \$connection['password'] ?? null, \$options);
        \$action = \$payload['action'] ?? 'inspect';

        if (\$action === 'inspect') {
            \$driver = \$driverName === 'sqlite'
                ? new \Rush\Tool\Schema\Driver\SqliteDriver()
                : new \Rush\Tool\Schema\Driver\MysqlDriver();
            \$version = \$driver->version(\$pdo);
            \$capabilities = \$driverName === 'mysql'
                ? \Rush\Tool\Schema\Driver\MysqlCapabilities::fromVersion(\$version)
                : null;
            \$result = [
                'ok' => true,
                'version' => \$version,
                'flavor' => \$capabilities?->flavor ?? \$driverName,
                'capabilities' => \$capabilities?->all() ?? [],
                'unsupported' => \$driver->unsupportedObjects(\$pdo),
                'state' => \$driver->inspect(\$pdo)->toArray(),
            ];
        } elseif (\$action === 'apply') {
            \$sql = \$payload['sql'] ?? '';
            if (!is_string(\$sql)) {
                throw new \RuntimeException('invalid sql');
            }
            (new \Rush\Tool\Schema\Sql\SqlExecutor())->apply(\$pdo, \$sql, \$driverName);
            \$result = ['ok' => true];
        } elseif (\$action === 'backup') {
            \$path = \$connection['database'] ?? '';
            if (!is_string(\$path) || \$path === '') {
                throw new \RuntimeException('missing database for backup');
            }
            \$stamp = gmdate('Ymd\\THis\\Z') .'-'. bin2hex(random_bytes(4));
            if (\$driverName === 'sqlite') {
                \$destination = \$path .'.'. \$stamp .'.bak';
                if (!copy(\$path, \$destination)) {
                    throw new \RuntimeException('could not copy sqlite backup');
                }
            } else {
                \$destination = sys_get_temp_dir() .'/rush-schema-'. \$stamp .'.sql';
                \$dumpBinary = 'mysqldump';
                \$probe = @proc_open(['mariadb-dump', '--version'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], \$probePipes);
                if (is_resource(\$probe)) {
                    fclose(\$probePipes[0]);
                    stream_get_contents(\$probePipes[1]);
                    stream_get_contents(\$probePipes[2]);
                    fclose(\$probePipes[1]);
                    fclose(\$probePipes[2]);
                    if (proc_close(\$probe) === 0) {
                        \$dumpBinary = 'mariadb-dump';
                    }
                }
                \$command = [\$dumpBinary, '--single-transaction', '--no-tablespaces', '--host='. (\$connection['host'] ?? '127.0.0.1'), '--port='. (\$connection['port'] ?? 3306), '--user='. (\$connection['user'] ?? ''), \$path];
                \$process = proc_open(\$command, [0 => ['pipe','r'], 1 => ['file', \$destination, 'w'], 2 => ['pipe','w']], \$pipes, null, array_merge(getenv(), ['MYSQL_PWD' => \$connection['password'] ?? '']));
                if (!is_resource(\$process)) {
                    throw new \RuntimeException('could not start '. \$dumpBinary);
                }
                fclose(\$pipes[0]);
                stream_get_contents(\$pipes[2]);
                fclose(\$pipes[2]);
                if (proc_close(\$process) !== 0) {
                    throw new \RuntimeException(\$dumpBinary .' failed');
                }
            }
            \$result = ['ok' => true, 'path' => \$destination];
        } else {
            throw new \RuntimeException('unknown action');
        }

        if ({$httpFlag}) {
            header('Content-Type: application/json');
        }
        echo json_encode(\$result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (\Throwable \$error) {
        if ({$httpFlag}) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode(['ok' => false, 'error' => \$error->getMessage()], JSON_UNESCAPED_SLASHES);
    }
}

PHP;
    }
}
