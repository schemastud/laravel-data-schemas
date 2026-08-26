<?php

namespace Schemastud\DataSchemas\Lifecycle;

use InvalidArgumentException;
use Schemastud\DataSchemas\Contracts\EnumeratesVersions;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;

/**
 * Filesystem {@see SchemaRegistry}: schemas are committed JSON artifacts under a
 * configurable directory, one file per `$id`. The `$id` is encoded into a safe filename —
 * NOT a reversible one: an over-long slug is elided in the MIDDLE, keeping the authority and
 * the stem+version at either end (see {@see pathFor()}; it used to keep only the last 60
 * characters, which threw the authority away). The true `$id` always comes from the file's own
 * `$id` field; the fingerprint suffix is what makes the name collision-safe. Resolving by `$id`
 * is still a direct lookup (same encoding both sides).
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
    /** Widest slug written before the middle is elided. See {@see pathFor()} for why 100. */
    public const MAX_SLUG = 100;

    /** How much of the head — the scheme, authority and leading path segments — always survives. */
    public const HEAD_WIDTH = 48;

    /** The marker that makes a truncated name legible AS truncated, rather than as a shorter id. */
    public const ELISION = '--';

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
     * Deterministic, collision-resistant filename for an `$id`. The 16-hex fingerprint of the id
     * guarantees uniqueness; the slug is there so a human can read the directory.
     *
     * ## The width cap keeps BOTH ends, and that is the whole point (beam-facade 147)
     *
     * This used to be `substr($slug, -60)` — truncation from the LEFT — which threw away the
     * discriminating half of every id. Measured across the flagship's 30 fleet artifacts on
     * 2026-08-26: `https://` survived as `ttps-`, `ps-`, `e-` or nothing at all, and four of eight
     * artifacts carried **no readable authority** in their name, with two `.com` and two `.app`
     * stems truncating to visually similar strings.
     *
     * Truncating from the RIGHT instead would be no better and arguably worse: every id in this
     * estate shares a long authority-plus-`schemas`-plus-`content-schema` prefix, so a right-cut
     * makes an entire directory look identical and moves the loss onto the slug that actually names
     * the thing. The ends are what discriminate; the middle is boilerplate. So an over-long slug is
     * **elided in the middle** ({@see ELISION}), which keeps the authority AND the stem+version.
     *
     * {@see MAX_SLUG} is 100 rather than 60 because it costs nothing — the longest id in the estate
     * slugs to 88 characters and so is not truncated at all — and every filename here is
     * `slug + 1 + 16 + 12` at worst, comfortably inside any filesystem's 255-byte limit.
     *
     * ⚠️ **Still not reversible, and a filename is still not a census.** The slug is lossy by
     * construction (`/` and `.` both become `-`, so it cannot be parsed back into an id), and
     * elision makes that visible rather than fixing it. The true `$id` comes from the file's own
     * `$id` field — {@see ids()} reads content for exactly this reason. beam-facade 141 had to
     * delete artifacts minted under a wrong authority and could only do it by loading every file;
     * that rule stands, this change only means a human can now see what they are looking at.
     *
     * ⚠️ **Changing this changes where every artifact is looked up.** {@see get()}/{@see has()} are
     * a direct filename lookup, so existing artifacts must be renamed to their new path or they
     * become invisible — a miss here is `null`, never an error. Done for the estate's fleet
     * directories when this landed.
     */
    protected function pathFor(string $id): string
    {
        $hash = substr(hash('sha256', $id), 0, 16);
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $id) ?? '';
        $slug = trim($slug, '-');

        if (strlen($slug) > self::MAX_SLUG) {
            $tail = self::MAX_SLUG - self::HEAD_WIDTH - strlen(self::ELISION);

            $slug = substr($slug, 0, self::HEAD_WIDTH).self::ELISION.substr($slug, -$tail);
        }

        return $this->directory.'/'.$slug.'.'.$hash.'.schema.json';
    }
}
