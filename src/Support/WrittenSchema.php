<?php

namespace Schemastud\DataSchemas\Support;

/**
 * A {@see GeneratedSchema} that has a destination — the provenance the disk leg adds.
 *
 * The path lives on the ITEM and never as the collection key: keying a collection by
 * path silently drops the second of two classes that resolve to one file, which is
 * precisely the defect {@see FileSchemaCollection::pathCollisions()} exists to report.
 */
class WrittenSchema extends GeneratedSchema
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        string $className,
        array $schema,
        public string $outputPath,
    ) {
        parent::__construct($className, $schema);
    }
}
