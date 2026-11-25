<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Driver;


final readonly class MysqlCapabilities
{
    public function __construct(
        public string $flavor,
        public string $version,
    ) {
    }


    public static function fromVersion(string $version): self
    {
        $flavor = stripos($version, 'mariadb') !== false ? 'mariadb' : 'mysql';

        return new self($flavor, $version);
    }


    public function supports(string $feature): bool
    {
        return match ($feature) {
            'check_constraints' => $this->atLeast(8, 0, 16) || $this->mariadbAtLeast(10, 2, 1),
            'enforced_check' => $this->flavor === 'mysql',
            'json_type' => $this->flavor === 'mysql',
            'json_expression_default' => $this->flavor === 'mysql' && $this->atLeast(8, 0, 13),
            'prefix_indexes' => true,
            'desc_indexes' => $this->flavor === 'mysql' || $this->mariadbAtLeast(10, 8, 0),
            'on_update' => true,
            'generated_columns' => false,
            'partitions' => false,
            default => false,
        };
    }


    /** @return array<string, bool> */
    public function all(): array
    {
        $features = [
            'check_constraints',
            'enforced_check',
            'json_type',
            'json_expression_default',
            'prefix_indexes',
            'desc_indexes',
            'on_update',
            'generated_columns',
            'partitions',
        ];

        $result = [];

        foreach ($features as $feature) {
            $result[$feature] = $this->supports($feature);
        }

        return $result;
    }


    private function atLeast(int $major, int $minor, int $patch): bool
    {
        if ($this->flavor !== 'mysql') {
            return false;
        }

        return version_compare($this->numericVersion(), "{$major}.{$minor}.{$patch}", '>=');
    }


    private function mariadbAtLeast(int $major, int $minor, int $patch): bool
    {
        if ($this->flavor !== 'mariadb') {
            return false;
        }

        return version_compare($this->numericVersion(), "{$major}.{$minor}.{$patch}", '>=');
    }


    private function numericVersion(): string
    {
        if (preg_match('/\A(\d+(?:\.\d+){1,2})/', $this->version, $matches) !== 1) {
            return '0.0.0';
        }

        $parts = explode('.', $matches[1]);

        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', array_slice($parts, 0, 3));
    }
}
