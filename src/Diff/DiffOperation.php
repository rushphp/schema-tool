<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Diff;


final readonly class DiffOperation
{
    public const CREATE_TABLE = 'create_table';
    public const DROP_TABLE = 'drop_table';
    public const REBUILD_TABLE = 'rebuild_table';
    public const CREATE_INDEX = 'create_index';
    public const DROP_INDEX = 'drop_index';
    public const CREATE_VIEW = 'create_view';
    public const DROP_VIEW = 'drop_view';

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $type,
        public array $data,
    ) {
    }
}
