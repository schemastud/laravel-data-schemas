<?php

namespace Schemastud\DataSchemas\Writers;

use Schemastud\DataSchemas\Support\FileSchemaCollection;
use Schemastud\DataSchemas\Support\SchemaDisk;
use Schemastud\DataSchemas\Support\WrittenSchema;

/**
 * Writes generated schema documents as JSON files on the `data-schemas` disk.
 *
 * NAMING. The contract names the idea (`Writer`), the implementation names the strategy —
 * the convention this package already keeps with `SchemaRegistry` ← `FilesystemSchemaRegistry`
 * and `PathGenerator` ← `DefaultPathGenerator`. `JsonSchemaWriter` named the payload, which
 * every implementation of `Writer` will always share, and so distinguished nothing.
 *
 * STORAGE. Through `Storage`, not `File`: see {@see SchemaDisk} for why the absolute-path
 * `File::put` had to go. Directory creation is the disk's job now, so there is no
 * `makeDirectory` here.
 */
class SchemaFileWriter implements Writer
{
    /** @param array<string, mixed> $config the `data-schemas` config */
    public function __construct(protected array $config) {}

    public function write(FileSchemaCollection $collection): void
    {
        $disk = SchemaDisk::for($this->config);

        foreach ($collection as $schema) {
            $disk->put(
                SchemaDisk::relative($schema->outputPath, $this->config),
                $this->encode($schema),
            );
        }
    }

    protected function encode(WrittenSchema $schema): string
    {
        $flags = JSON_UNESCAPED_SLASHES;

        if ($this->config['format_output'] ?? true) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return (string) json_encode($schema->schema, $flags);
    }
}
