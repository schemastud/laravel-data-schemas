<?php

namespace Schemastud\DataSchemas\Support;

/**
 * The ONE place this package reads schema files back off the disk.
 *
 * It exists so the collections do not. A collection that reaches for a filesystem cannot
 * be reasoned about, cannot be unit-tested without a fixture tree, and quietly makes every
 * question it answers depend on where it happens to be standing —
 * {@see SchemaCollection::diffAgainst()} is pure precisely because this class holds the
 * impure half.
 *
 * Missing files are ABSENT from the result rather than null entries: "no document there"
 * and "a document that failed to parse" are the same answer to the only caller there is
 * (drift), and both mean the file must be regenerated.
 */
class SchemaFileReader
{
    /** @param array<string, mixed> $config the `data-schemas` config */
    public function __construct(protected array $config) {}

    /**
     * One document, or null when it is absent or is not decodable JSON.
     *
     * @return array<string, mixed>|null
     */
    public function document(string $outputPath): ?array
    {
        $disk = SchemaDisk::for($this->config);
        $relative = SchemaDisk::relative($outputPath, $this->config);

        if (! $disk->exists($relative)) {
            return null;
        }

        $decoded = json_decode((string) $disk->get($relative), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The on-disk counterpart of each written schema, keyed by class name — the exact
     * shape {@see SchemaCollection::diffAgainst()} takes.
     *
     * @param  FileSchemaCollection<array-key, WrittenSchema>  $schemas
     * @return array<string, array<string, mixed>>
     */
    public function documentsFor(FileSchemaCollection $schemas): array
    {
        $documents = [];

        foreach ($schemas as $schema) {
            $document = $this->document($schema->outputPath);

            if ($document !== null) {
                $documents[$schema->className] = $document;
            }
        }

        return $documents;
    }
}
