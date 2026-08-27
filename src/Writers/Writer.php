<?php

namespace Schemastud\DataSchemas\Writers;

use Schemastud\DataSchemas\Support\FileSchemaCollection;

/**
 * Persists a set of generated schema documents.
 *
 * Takes a {@see FileSchemaCollection} rather than the base `SchemaCollection`, because a
 * schema with no destination is not writable and a writer that has to guess one is a path
 * generator wearing a disguise. Widening a parameter is legal in an implementation, so a
 * host's existing `write(SchemaCollection $c)` still satisfies this.
 */
interface Writer
{
    public function write(FileSchemaCollection $collection): void;
}
