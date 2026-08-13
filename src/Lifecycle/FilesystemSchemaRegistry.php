<?php

namespace Schemastud\DataSchemas\Lifecycle;

use InvalidArgumentException;
use Schemastud\DataSchemas\Contracts\EnumeratesVersions;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;

/**
 * Filesystem {@see SchemaRegistry}: schemas are committed JSON artifacts under a
 * configurable directory, one file per `$id`. The `$id` is encoded into a safe filename —
 * NOT a reversible one: the slug keeps only the LAST 60 characters of the encoded `$id`
 * (a long absolute URI loses its head — e.g. the leading `ht` of `https`), so the true
 * `$id` always comes from the file's own `$id` field; the fingerprint suffix is what makes
 * the name collision-safe. Resolving by `$id` is still a direct lookup (same encoding both
 * sides).
 *
 * Immutability / write-once: registering an `$id` that already exists with a
 * DIFFERENT structural fingerprint throws {@see SchemaRegistryConflict}. The
 * same `$id` with the same fingerprint is an idempotent no-op (re-publish).
 *
 * Resolves any `$id` at any depth — a top-level document or a nested addressable
 * node — because every addressable resource is stored under its own `$id`.
 */
class FilesystemSchemaRegistry implements EnumeratesVersions, SchemaRegistry
{
    public function __construct(
        protected string $directory,
    ) {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
    }

    public function register(array $schema): void
    {
        $id = $schema['$id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new InvalidArgumentException('Cannot register a schema without an $id.');
        }

        $incoming = SchemaFingerprint::of($schema);

        $existing = $this->get($id);
        if ($existing !== null) {
            $stored = SchemaFingerprint::of($existing);
            if ($stored === $incoming) {
                return; // Idempotent re-publish of the identical shape.
            }

            throw new SchemaRegistryConflict($id, $stored, $incoming);
        }

        file_put_contents(
            $this->pathFor($id),
            json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    public function get(string $id): ?array
    {
        $path = $this->pathFor($id);
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function has(string $id): bool
    {
        return is_file($this->pathFor($id));
    }

    public function ids(): array
    {
        $ids = [];
        foreach (glob($this->directory.'/*.schema.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded) && isset($decoded['$id'])) {
                $ids[] = $decoded['$id'];
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * The version integers registered under `$stem`, ascending and de-duplicated.
     * The committed store has no index, so this parses its known `$id`s: an id
     * splits at its last `/` into a stem and a trailing integer version; ids whose
     * stem matches and whose tail is a non-negative integer contribute a version.
     */
    public function versionsFor(string $stem): array
    {
        $versions = [];

        foreach ($this->ids() as $id) {
            $pos = strrpos($id, '/');
            if ($pos === false || substr($id, 0, $pos) !== $stem) {
                continue;
            }

            $tail = substr($id, $pos + 1);
            if ($tail !== '' && ctype_digit($tail)) {
                $versions[] = (int) $tail;
            }
        }

        $versions = array_values(array_unique($versions));
        sort($versions);

        return $versions;
    }

    /**
     * Deterministic, collision-resistant filename for an `$id`. A short hash
     * guarantees uniqueness; a slugged tail keeps files human-recognizable.
     */
    protected function pathFor(string $id): string
    {
        $hash = substr(hash('sha256', $id), 0, 16);
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $id) ?? '';
        $slug = trim($slug, '-');
        $slug = substr($slug, -60);

        return $this->directory.'/'.$slug.'.'.$hash.'.schema.json';
    }
}
