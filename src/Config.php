<?php declare(strict_types=1);

namespace Rush\Tool\Schema;

use JsonException;
use Rush\Tool\Schema\Config\DatabaseConfig;
use Rush\Tool\Schema\Config\TargetConfig;
use Rush\Tool\Schema\Exception\ConfigException;


final class Config
{
    /** @var list<string> */
    public const SUGGESTED_TARGETS = ['local', 'development', 'staging', 'production'];

    /** @var list<string> */
    public const DRIVERS = ['sqlite', 'mysql'];

    /** @var list<string> */
    public const TRANSPORTS = ['local', 'ssh', 'http'];

    /** @var array<string, DatabaseConfig> */
    private array $databases;


    /** @param array<string, DatabaseConfig> $databases */
    private function __construct(array $databases)
    {
        $this->databases = $databases;
    }


    public static function load(string $projectRoot): self
    {
        $file = rtrim($projectRoot, '/') .'/.rush/schema/config.json';

        if ( ! is_file($file) || ! is_readable($file)) {
            throw new ConfigException('schema: .rush/schema/config.json not found or unreadable');
        }

        $raw = file_get_contents($file);

        if ($raw === false) {
            throw new ConfigException('schema: could not read .rush/schema/config.json');
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ConfigException('schema: invalid .rush/schema/config.json: '. $exception->getMessage(), 0, $exception);
        }

        if ( ! is_array($data)) {
            throw new ConfigException('schema: .rush/schema/config.json must contain an object');
        }

        $definitions = $data['databases'] ?? null;

        if ( ! is_array($definitions) || $definitions === []) {
            throw new ConfigException("schema: 'databases' must be a non-empty object");
        }

        $databases = [];

        foreach ($definitions as $name => $definition) {
            if ( ! is_string($name) || preg_match('/\A[a-z][a-z0-9_-]*\z/', $name) !== 1) {
                throw new ConfigException('schema: invalid database name');
            }

            if ( ! is_array($definition)) {
                throw new ConfigException("schema: database '{$name}' must be an object");
            }

            $databases[$name] = self::buildDatabase($name, $definition);
        }

        return new self($databases);
    }


    public function database(?string $name = null): DatabaseConfig
    {
        if ($name === null || $name === '') {
            $names = $this->names();

            if (count($names) === 1) {
                return $this->databases[$names[0]];
            }

            throw new ConfigException(
                'schema: multiple databases configured; use --database. available: '. implode(', ', $names),
            );
        }

        if ( ! isset($this->databases[$name])) {
            throw new ConfigException(
                "schema: unknown database '{$name}'. available: ". implode(', ', $this->names()),
            );
        }

        return $this->databases[$name];
    }


    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->databases);
    }


    /** @param array<array-key, mixed> $definition */
    private static function buildDatabase(string $name, array $definition): DatabaseConfig
    {
        $driver = self::requiredString($definition, 'driver');

        if ( ! in_array($driver, self::DRIVERS, true)) {
            throw new ConfigException(
                "schema: database '{$name}' has invalid driver '{$driver}'. use: ". implode(', ', self::DRIVERS),
            );
        }

        $version = self::optionalString($definition, 'version');
        $targets = $definition['targets'] ?? null;

        if ( ! is_array($targets) || $targets === []) {
            throw new ConfigException("schema: database '{$name}' requires a non-empty 'targets' object");
        }

        $parsed = [];

        foreach ($targets as $targetName => $target) {
            if ( ! is_string($targetName) || preg_match('/\A[a-z][a-z0-9_-]*\z/', $targetName) !== 1) {
                throw new ConfigException("schema: database '{$name}' has invalid target name");
            }

            if ( ! is_array($target)) {
                throw new ConfigException("schema: target '{$targetName}' must be an object");
            }

            $parsed[$targetName] = self::target($targetName, $driver, $target);
        }

        if ( ! isset($parsed['local'])) {
            throw new ConfigException("schema: database '{$name}' requires a 'local' target");
        }

        return new DatabaseConfig($name, $driver, $version, $parsed);
    }


    /** @param array<array-key, mixed> $target */
    private static function target(string $name, string $driver, array $target): TargetConfig
    {
        $transport = self::requiredString($target, 'transport');

        if ( ! in_array($transport, self::TRANSPORTS, true)) {
            throw new ConfigException(
                "schema: target '{$name}' has invalid transport '{$transport}'. use: ". implode(', ', self::TRANSPORTS),
            );
        }

        foreach ($target as $key => $value) {
            if (is_string($key) && $key === 'driver') {
                throw new ConfigException("schema: target '{$name}' must not override the database driver");
            }
        }

        $database = self::requiredString($target, 'database');

        if ($transport === 'http') {
            return self::httpTarget($name, $driver, $database, $target);
        }

        $host = self::optionalString($target, 'host');
        $port = self::optionalInt($target, 'port', 3306);
        $user = self::optionalString($target, 'user');
        $password = self::optionalString($target, 'password');

        if ($driver === 'mysql' && ($host === null || $user === null)) {
            throw new ConfigException("schema: target '{$name}' requires 'host' and 'user'");
        }

        if ($transport === 'local') {
            return new TargetConfig(
                $name,
                $transport,
                $database,
                $driver,
                $host,
                $port,
                $user,
                $password,
            );
        }

        $ssh = $target['ssh'] ?? null;

        if ( ! is_array($ssh)) {
            throw new ConfigException("schema: target '{$name}' requires an 'ssh' object");
        }

        return new TargetConfig(
            $name,
            $transport,
            $database,
            $driver,
            $host,
            $port,
            $user,
            $password,
            true,
            null,
            null,
            self::requiredString($ssh, 'host'),
            self::optionalInt($ssh, 'port', 22),
            self::requiredString($ssh, 'user'),
            self::optionalString($ssh, 'identity'),
            self::optionalString($ssh, 'password_file'),
            self::requiredString($ssh, 'php_binary'),
        );
    }


    /** @param array<array-key, mixed> $target */
    private static function httpTarget(string $name, string $driver, string $database, array $target): TargetConfig
    {
        $url = self::requiredString($target, 'url');
        $secure = self::optionalBool($target, 'secure', true);

        if ($secure && ! str_starts_with($url, 'https://')) {
            throw new ConfigException("schema: target '{$name}' requires an https url when secure is true");
        }

        foreach (['host', 'port', 'user', 'password', 'ssh'] as $key) {
            if (array_key_exists($key, $target)) {
                throw new ConfigException(
                    "schema: target '{$name}' must not define '{$key}'; http targets use schema.remote.json on the server",
                );
            }
        }

        return new TargetConfig(
            $name,
            'http',
            $database,
            $driver,
            null,
            null,
            null,
            null,
            $secure,
            $url,
            self::requiredString($target, 'token_file'),
        );
    }


    /** @param array<array-key, mixed> $data */
    private static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if ( ! is_string($value) || trim($value) === '') {
            throw new ConfigException("schema: '{$key}' must be a non-empty string");
        }

        return trim($value);
    }


    /** @param array<array-key, mixed> $data */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if ( ! is_string($value) || trim($value) === '') {
            throw new ConfigException("schema: '{$key}' must be a non-empty string");
        }

        return trim($value);
    }


    /** @param array<array-key, mixed> $data */
    private static function optionalInt(array $data, string $key, int $default): int
    {
        $value = $data[$key] ?? $default;

        if ( ! is_int($value) || $value < 1) {
            throw new ConfigException("schema: '{$key}' must be a positive integer");
        }

        return $value;
    }


    /** @param array<array-key, mixed> $data */
    private static function optionalBool(array $data, string $key, bool $default): bool
    {
        $value = $data[$key] ?? $default;

        if ( ! is_bool($value)) {
            throw new ConfigException("schema: '{$key}' must be a boolean");
        }

        return $value;
    }
}
