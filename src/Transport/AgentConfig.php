<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Transport;

use JsonException;
use RuntimeException;


final class AgentConfig
{
    /**
     * @param array<string, array<string, mixed>> $databases
     */
    private function __construct(
        private readonly string $token,
        private readonly array $databases,
    ) {
    }


    public static function fromEnvironment(): self
    {
        $path = getenv('RUSH_SCHEMA_AGENT_CONFIG');

        if ( ! is_string($path) || trim($path) === '') {
            throw new RuntimeException('RUSH_SCHEMA_AGENT_CONFIG is not set');
        }

        return self::fromPath($path);
    }


    public static function fromPath(string $path): self
    {
        if ( ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('agent config is not readable');
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException('agent config could not be read');
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('agent config is not valid JSON');
        }

        if ( ! is_array($data)) {
            throw new RuntimeException('agent config must be an object');
        }

        $token = $data['token'] ?? null;

        if ( ! is_string($token) || trim($token) === '') {
            throw new RuntimeException('agent config requires a token');
        }

        $databases = $data['databases'] ?? null;

        if ( ! is_array($databases) || $databases === []) {
            throw new RuntimeException('agent config requires a non-empty databases object');
        }

        $parsed = [];

        foreach ($databases as $name => $connection) {
            if ( ! is_string($name) || preg_match('/\A[a-z][a-z0-9_-]*\z/', $name) !== 1 || ! is_array($connection)) {
                throw new RuntimeException('agent config has an invalid database entry');
            }

            /** @var array<string, mixed> $connection */
            $parsed[$name] = self::buildConnection($name, $connection);
        }

        return new self(trim($token), $parsed);
    }


    public function token(): string
    {
        return $this->token;
    }


    /** @return array<string, mixed> */
    public function connection(string $database): array
    {
        if ( ! isset($this->databases[$database])) {
            throw new RuntimeException("agent config has no database '{$database}'");
        }

        return $this->databases[$database];
    }


    /**
     * @param array<string, mixed> $connection
     * @return array<string, mixed>
     */
    private static function buildConnection(string $name, array $connection): array
    {
        $driver = $connection['driver'] ?? null;

        if ( ! is_string($driver) || ! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException("agent config database '{$name}' has an invalid driver");
        }

        if ($driver === 'sqlite') {
            $path = $connection['path'] ?? null;
            $dsn = $connection['dsn'] ?? null;

            if (is_string($dsn) && $dsn !== '') {
                return [
                    'driver' => 'sqlite',
                    'dsn' => $dsn,
                    'database' => is_string($path) && $path !== '' ? $path : self::sqlitePath($dsn),
                    'user' => null,
                    'password' => null,
                ];
            }

            if ( ! is_string($path) || trim($path) === '') {
                throw new RuntimeException("agent config database '{$name}' requires a path");
            }

            return [
                'driver' => 'sqlite',
                'dsn' => 'sqlite:'. $path,
                'database' => $path,
                'user' => null,
                'password' => null,
            ];
        }

        $dsn = $connection['dsn'] ?? null;

        if ( ! is_string($dsn) || $dsn === '') {
            $host = $connection['host'] ?? null;
            $database = $connection['database'] ?? null;

            if ( ! is_string($host) || $host === '' || ! is_string($database) || $database === '') {
                throw new RuntimeException("agent config database '{$name}' requires host and database");
            }

            $port = $connection['port'] ?? 3306;

            if ( ! is_int($port) || $port < 1) {
                throw new RuntimeException("agent config database '{$name}' has an invalid port");
            }

            $dsn = 'mysql:host='. $host .';port='. $port .';dbname='. $database .';charset=utf8mb4';
        }

        $database = $connection['database'] ?? null;

        if ( ! is_string($database) || $database === '') {
            throw new RuntimeException("agent config database '{$name}' requires database");
        }

        return [
            'driver' => 'mysql',
            'dsn' => $dsn,
            'database' => $database,
            'host' => is_string($connection['host'] ?? null) ? $connection['host'] : '127.0.0.1',
            'port' => is_int($connection['port'] ?? null) ? $connection['port'] : 3306,
            'user' => is_string($connection['user'] ?? null) ? $connection['user'] : null,
            'password' => is_string($connection['password'] ?? null) ? $connection['password'] : null,
        ];
    }


    private static function sqlitePath(string $dsn): string
    {
        return str_starts_with($dsn, 'sqlite:') ? substr($dsn, 7) : $dsn;
    }
}
