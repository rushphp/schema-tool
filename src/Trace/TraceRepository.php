<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Trace;

use JsonException;
use Rush\Support\FileSystem;
use Rush\Tool\Schema\Canonical\CanonicalJson;
use Rush\Tool\Schema\Canonical\SchemaHasher;
use Rush\Tool\Schema\Domain\SchemaState;
use Rush\Tool\Schema\Exception\TraceException;
use Rush\Tool\Schema\Paths\SchemaPaths;


final class TraceRepository
{
    public function __construct(
        private readonly SchemaPaths $paths,
    ) {
    }


    public function paths(): SchemaPaths
    {
        return $this->paths;
    }


    public function initialized(): bool
    {
        return is_file($this->paths->headFile());
    }


    public function head(): ?string
    {
        if ( ! is_file($this->paths->headFile())) {
            return null;
        }

        $value = trim((string) file_get_contents($this->paths->headFile()));

        return $value === '' ? null : $value;
    }


    public function setHead(string $save): void
    {
        $this->write($this->paths->headFile(), $save ."\n");
    }


    public function save(string $id): ?Save
    {
        $data = $this->readJson($this->paths->saveFile($id));

        return $data === null ? null : Save::fromArray($data);
    }


    public function requireSave(string $id): Save
    {
        $save = $this->save($id);

        if ($save === null) {
            throw new TraceException("schema: save not found: {$id}");
        }

        return $save;
    }


    public function writeSave(Save $save, string $up, string $down): void
    {
        $this->write($this->paths->saveUpFile($save->id), $up);
        $this->write($this->paths->saveDownFile($save->id), $down);
        $this->writeJson($this->paths->saveFile($save->id), $save->toArray());
    }


    public function saveUp(Save $save): string
    {
        return $this->read($this->paths->saveUpFile($save->id));
    }


    public function saveDown(Save $save): string
    {
        return $this->read($this->paths->saveDownFile($save->id));
    }


    /** @return list<Save> */
    public function allSaves(): array
    {
        $files = glob($this->paths->savesDirectory() .'/*.json') ?: [];
        $saves = [];

        foreach ($files as $file) {
            $id = basename($file, '.json');
            $data = $this->readJson($file);

            if ($data !== null) {
                $saves[] = Save::fromArray($data);
            }
        }

        usort($saves, static fn (Save $left, Save $right): int => strcmp($left->id, $right->id));

        return $saves;
    }


    /** @return list<Save> old to new */
    public function chain(?string $head = null): array
    {
        $head ??= $this->head();

        if ($head === null) {
            return [];
        }

        $saves = [];
        $seen = [];
        $id = $head;

        while ($id !== null) {
            if (isset($seen[$id])) {
                throw new TraceException("schema: save cycle detected at {$id}");
            }

            $seen[$id] = true;
            $save = $this->requireSave($id);
            array_unshift($saves, $save);
            $id = $save->parent;
        }

        return $saves;
    }


    public function isAncestor(string $ancestor, string $descendant): bool
    {
        $id = $descendant;

        while ($id !== null) {
            if ($id === $ancestor) {
                return true;
            }

            $save = $this->save($id);

            if ($save === null) {
                return false;
            }

            $id = $save->parent;
        }

        return false;
    }


    public function writeState(SchemaState $state): string
    {
        $hasher = new SchemaHasher();
        $hash = $hasher->hash($state);
        $file = $this->paths->stateFile($hash);

        if ( ! is_file($file)) {
            $this->write($file, CanonicalJson::pretty($state->toArray()));
        }

        return $hash;
    }


    public function state(string $hash): SchemaState
    {
        $file = $this->paths->stateFile($hash);

        if ( ! is_file($file)) {
            throw new TraceException("schema: snapshot not found for {$hash}");
        }

        $data = $this->readJson($file);

        if ($data === null) {
            throw new TraceException("schema: invalid snapshot for {$hash}");
        }

        return SchemaState::fromArray($data);
    }


    public function stateExists(string $hash): bool
    {
        return is_file($this->paths->stateFile($hash));
    }


    public function writeCheckpoint(Checkpoint $checkpoint, string $release): void
    {
        $this->write($this->paths->checkpointReleaseFile($checkpoint->id), $release);
        $this->writeJson($this->paths->checkpointFile($checkpoint->id), $checkpoint->toArray());
    }


    public function checkpoint(string $id): ?Checkpoint
    {
        $data = $this->readJson($this->paths->checkpointFile($id));

        return $data === null ? null : Checkpoint::fromArray($data);
    }


    public function requireCheckpoint(string $id): Checkpoint
    {
        $checkpoint = $this->checkpoint($id);

        if ($checkpoint === null) {
            throw new TraceException("schema: checkpoint not found: {$id}");
        }

        return $checkpoint;
    }


    public function checkpointRelease(Checkpoint $checkpoint): string
    {
        return $this->read($this->paths->checkpointReleaseFile($checkpoint->id));
    }


    /** @return list<Checkpoint> */
    public function allCheckpoints(): array
    {
        $files = glob($this->paths->checkpointsDirectory() .'/*.json') ?: [];
        $checkpoints = [];

        foreach ($files as $file) {
            $data = $this->readJson($file);

            if ($data !== null) {
                $checkpoints[] = Checkpoint::fromArray($data);
            }
        }

        usort($checkpoints, static fn (Checkpoint $left, Checkpoint $right): int => strcmp($left->id, $right->id));

        return $checkpoints;
    }


    public function checkpointForSave(string $saveId): ?Checkpoint
    {
        foreach ($this->allCheckpoints() as $checkpoint) {
            if ($checkpoint->save === $saveId) {
                return $checkpoint;
            }
        }

        return null;
    }


    public function latestCheckpointInAncestry(string $saveId): ?Checkpoint
    {
        $id = $saveId;

        while ($id !== null) {
            $checkpoint = $this->checkpointForSave($id);

            if ($checkpoint !== null) {
                return $checkpoint;
            }

            $save = $this->save($id);

            if ($save === null) {
                return null;
            }

            $id = $save->parent;
        }

        return null;
    }


    public function latestCheckpoint(): ?Checkpoint
    {
        $head = $this->head();

        return $head === null ? null : $this->latestCheckpointInAncestry($head);
    }


    /** @return list<Save> */
    public function savesForCheckpoint(Checkpoint $checkpoint): array
    {
        $base = $checkpoint->baseCheckpoint;
        $baseSave = $base === null ? null : $this->requireCheckpoint($base)->save;
        $chain = $this->chain();
        $saves = [];
        $afterBase = $baseSave === null;

        foreach ($chain as $save) {
            if ( ! $afterBase) {
                if ($save->id === $baseSave) {
                    $afterBase = true;
                }

                continue;
            }

            $saves[] = $save;

            if ($save->id === $checkpoint->save) {
                break;
            }
        }

        return $saves;
    }


    /** @return array<string, mixed>|null */
    public function targetRecord(string $target): ?array
    {
        return $this->readJson($this->paths->targetRecord($target));
    }


    /** @param array<string, mixed> $data */
    public function writeTargetRecord(string $target, array $data): void
    {
        $this->writeJson($this->paths->targetRecord($target), $data);
    }


    /** @return array<string, mixed>|null */
    private function readJson(string $path): ?array
    {
        if ( ! is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new TraceException("schema: could not read {$path}");
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new TraceException("schema: invalid JSON in {$path}: ". $exception->getMessage(), 0, $exception);
        }

        if ( ! is_array($data)) {
            throw new TraceException("schema: expected object in {$path}");
        }

        /** @var array<string, mixed> $data */
        return $data;
    }


    private function read(string $path): string
    {
        if ( ! is_file($path)) {
            throw new TraceException("schema: file not found: {$path}");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new TraceException("schema: could not read {$path}");
        }

        return $contents;
    }


    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $this->write($path, CanonicalJson::pretty($data));
    }


    private function write(string $path, string $contents): void
    {
        FileSystem::write($path, $contents, 0644);
    }
}
