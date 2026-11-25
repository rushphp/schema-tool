<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Driver;

use Rush\Tool\Schema\Diff\SchemaDiff;
use Rush\Tool\Schema\Domain\SchemaState;


interface SqlDialectInterface
{
    public function driver(): string;

    public function release(SchemaState $state): string;

    public function sql(SchemaDiff $diff): string;
}
