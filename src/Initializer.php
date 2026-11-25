<?php declare(strict_types=1);

namespace Rush\Tool\Schema;

use Rush\Support\FileSystem;
use Rush\Tool\Schema\Exception\SecurityException;


final class Initializer
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ?string $examplePath = null,
    ) {
    }


    /** @return list<string> */
    public function run(): array
    {
        $base = rtrim($this->projectRoot, '/') .'/.rush/schema';
        $config = $base .'/config.json';
        $example = $this->examplePath ?? dirname(__DIR__) .'/schema.example.json';

        if (is_link($base)) {
            throw new SecurityException("refusing to use symlink: {$base}");
        }

        $lines = [];

        foreach ([$base, $base .'/ssh', $base .'/tokens'] as $directory) {
            $lines[] = $this->directory($directory);
        }

        if (is_link($config)) {
            throw new SecurityException("refusing to use symlink: {$config}");
        }

        if (is_file($config)) {
            $lines[] = 'exists '. $this->display($config);
        } else {
            FileSystem::copyFile($example, $config);
            FileSystem::chmod($config, 0600);
            $lines[] = 'created '. $this->display($config);
        }

        return $lines;
    }


    private function directory(string $path): string
    {
        if (is_link($path)) {
            throw new SecurityException("refusing to use symlink: {$path}");
        }

        if (is_dir($path)) {
            return 'exists '. $this->display($path);
        }

        FileSystem::ensureDirectory($path, 0700);

        return 'created '. $this->display($path);
    }


    private function display(string $path): string
    {
        $root = rtrim($this->projectRoot, '/');

        if (str_starts_with($path, $root .'/')) {
            return substr($path, strlen($root) + 1);
        }

        return $path;
    }
}
