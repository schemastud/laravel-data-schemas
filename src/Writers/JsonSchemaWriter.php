<?php

namespace Schemastud\DataSchemas\Writers;

use Illuminate\Support\Facades\File;
use Schemastud\DataSchemas\Support\SchemaCollection;

class JsonSchemaWriter implements Writer
{
    public function __construct(protected array $config) {}

    public function write(SchemaCollection $collection): void
    {
        foreach ($collection as $generatedSchema) {
            $this->writeSchemaFile($generatedSchema->outputPath, $generatedSchema->schema);
        }
    }

    protected function writeSchemaFile(string $path, array $schema): void
    {
        // Ensure directory exists
        $directory = dirname($path);
        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        // Format JSON if configured
        $json = $this->config['format_output']
            ? json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : json_encode($schema, JSON_UNESCAPED_SLASHES);

        File::put($path, $json);
    }
}
