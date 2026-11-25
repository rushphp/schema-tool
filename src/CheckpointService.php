<?php declare(strict_types=1);

namespace Rush\Tool\Schema;

use Rush\Tool\Schema\Canonical\SchemaHasher;
use Rush\Tool\Schema\Config\DatabaseConfig;
use Rush\Tool\Schema\Driver\DriverFactory;
use Rush\Tool\Schema\Exception\TraceException;
use Rush\Tool\Schema\Trace\Checkpoint;
use Rush\Tool\Schema\Trace\TraceRepository;


final class CheckpointService
{
    public function __construct(
        private readonly TraceRepository $trace,
        private readonly CheckpointVerifier $verifier,
    ) {
    }


    public function create(DatabaseConfig $database, ?string $label): Checkpoint
    {
        $head = $this->trace->head();

        if ($head === null) {
            throw new TraceException('schema: no HEAD; run schema init');
        }

        if ($this->trace->checkpointForSave($head) !== null) {
            throw new TraceException("schema: HEAD {$head} already has a checkpoint");
        }

        $base = $this->trace->latestCheckpointInAncestry($head);

        if ($base === null) {
            throw new TraceException('schema: no base checkpoint found');
        }

        $save = $this->trace->requireSave($head);
        $now = gmdate('c');
        $checkpoint = new Checkpoint(
            'cp_'. Ulid::generate(),
            $head,
            $label,
            $base->id,
            $save->toHash,
            $now,
            $now,
            [],
        );

        $this->verifier->verify($checkpoint, $database);

        $state = $this->trace->state($save->toHash);
        $dialect = DriverFactory::make($database->driver)->dialect();
        $release = $dialect->release($state);

        $checkpoint = new Checkpoint(
            $checkpoint->id,
            $checkpoint->save,
            $checkpoint->label,
            $checkpoint->baseCheckpoint,
            $checkpoint->schemaHash,
            $checkpoint->createdAt,
            $checkpoint->verifiedAt,
            ['release' => SchemaHasher::hashSql($release)],
        );

        $this->trace->writeCheckpoint($checkpoint, $release);

        return $checkpoint;
    }
}
