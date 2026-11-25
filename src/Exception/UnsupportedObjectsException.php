<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Exception;


final class UnsupportedObjectsException extends SchemaException
{
    /**
     * @param list<string> $objects
     */
    public static function fromObjects(array $objects): self
    {
        return new self(
            'database contains unsupported objects: '. implode(', ', $objects)
            .'. Schema manages structure only. Move triggers, procedures, functions and other unsupported objects to the application.',
        );
    }
}
