<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Paths;

use Rush\Tool\Schema\Exception\SecurityException;


final class SchemaPaths
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly string $database,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_-]*\z/', $database) !== 1) {
            throw new SecurityException("schema: invalid database name '{$database}'");
        }
    }


    public function database(): string
    {
        return $this->database;
    }


    public function projectRoot(): string
    {
        return rtrim($this->projectRoot, '/');
    }


    public function localBase(): string
    {
        return $this->projectRoot() .'/.rush/schema';
    }


    public function configFile(): string
    {
        return $this->localBase() .'/config.json';
    }


    public function sshDirectory(): string
    {
        return $this->localBase() .'/ssh';
    }


    public function tokensDirectory(): string
    {
        return $this->localBase() .'/tokens';
    }


    public function localDatabaseDirectory(): string
    {
        return $this->localBase() .'/local/'. $this->database;
    }


    public function backupsDirectory(): string
    {
        return $this->localDatabaseDirectory() .'/backups';
    }


    public function temporaryDirectory(): string
    {
        return $this->localDatabaseDirectory() .'/tmp';
    }


    public function targetRecord(string $target): string
    {
        return $this->localDatabaseDirectory() .'/targets/'. $target .'.json';
    }


    public function versionedBase(): string
    {
        return $this->projectRoot() .'/schema';
    }


    public function databaseDirectory(): string
    {
        return $this->versionedBase() .'/'. $this->database;
    }


    public function headFile(): string
    {
        return $this->databaseDirectory() .'/HEAD';
    }


    public function savesDirectory(): string
    {
        return $this->databaseDirectory() .'/saves';
    }


    public function statesDirectory(): string
    {
        return $this->databaseDirectory() .'/states';
    }


    public function checkpointsDirectory(): string
    {
        return $this->databaseDirectory() .'/checkpoints';
    }


    public function saveFile(string $id): string
    {
        return $this->savesDirectory() .'/'. $id .'.json';
    }


    public function saveUpFile(string $id): string
    {
        return $this->savesDirectory() .'/'. $id .'.up.sql';
    }


    public function saveDownFile(string $id): string
    {
        return $this->savesDirectory() .'/'. $id .'.down.sql';
    }


    public function stateFile(string $hash): string
    {
        return $this->statesDirectory() .'/'. self::hashName($hash) .'.json';
    }


    public function checkpointFile(string $id): string
    {
        return $this->checkpointsDirectory() .'/'. $id .'.json';
    }


    public function checkpointReleaseFile(string $id): string
    {
        return $this->checkpointsDirectory() .'/'. $id .'.release.sql';
    }


    public static function hashName(string $hash): string
    {
        return str_starts_with($hash, 'sha256:') ? substr($hash, 7) : $hash;
    }
}
