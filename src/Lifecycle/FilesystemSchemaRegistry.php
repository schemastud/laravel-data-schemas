<?php

namespace Schemastud\DataSchemas\Lifecycle;

use InvalidArgumentException;
use RuntimeException;
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
 * Across processes too: the check and the write run under {@see withLock()}.
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

    /** The lock file beside the artifacts. Dot-named and not `*.schema.json`, so no listing or glob sees it. */
    public const LOCK_FILE = '.registry.lock';

    /** How deep this instance is inside its own {@see withLock()}: a nested call does not lock again. */
    private int $lockDepth = 0;

    public function __construct(
        protected string $directory,
    ) {
        // Atomic "make it if missing": the registry is built on every request and worker that resolves it, so two
        // processes can both see no directory and race to create it. The loser's mkdir fails with "File exists",
        // which Laravel's error handler turns into an exception (agreements 07a). Only a directory that is still
        // missing after the attempt is an error.
        if (is_dir($this->directory) || @mkdir($this->directory, 0775, true)) {
            return;
        }

        clearstatcache(true, $this->directory);
        if (! is_dir($this->directory)) {
            throw new RuntimeException("Cannot create the schema registry directory [{$this->directory}].");
        }
    }

    public function register(array $schema): void
    {
        $id = $schema['$id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new InvalidArgumentException('Cannot register a schema without an $id.');
        }

        MigrationKeywordPlacement::assertTopLevelOnly($schema);

        $incoming = SchemaFingerprint::of($schema);

        // A look without the lock first: an artifact is only ever renamed into place whole, so what get() reads is
        // a whole artifact, and the common boot (an idempotent re-publish) neither locks nor creates the lock file.
        if ($this->alreadyRegistered($id, $incoming)) {
            return;
        }

        // Two writers of different shapes could both find nothing above and both write: the last rename won and
        // both registrations "succeeded". Under the lock, the second writer waits, looks again, and is refused.
        $this->withLock(function () use ($id, $incoming, $schema): void {
            if ($this->alreadyRegistered($id, $incoming)) {
                return;
            }

            $this->writeAtomically(
                $this->pathFor($id),
                (string) json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );
        });
    }

    /**
     * Run `$critical` holding this registry's exclusive lock: `flock(LOCK_EX)` on {@see LOCK_FILE} in its directory.
     * {@see register()} holds it around its check and its write; a caller that decides what to register from what
     * the directory holds (e.g. sweeping a stale artifact first) holds it around both, so that decision and the
     * write are one step for every other process. Blocks while another process holds it.
     *
     * Re-entrant for THIS instance only: `register()` inside `withLock()` on the same instance does not lock again.
     * Another instance on the same directory in the same process waits like another process (an `flock` is held by
     * an open file, not by a process), so a callable must use the registry it is given.
     *
     * The lock file stays: unlinking it would race the next holder, who may already have it open. `flock` is
     * advisory and local; it does not reach across hosts sharing the directory over a network filesystem.
     *
     * @template T
     *
     * @param  callable(self): T  $critical
     * @return T
     */
    public function withLock(callable $critical): mixed
    {
        if ($this->lockDepth > 0) {
            return $critical($this);
        }

        $path = $this->directory.'/'.self::LOCK_FILE;
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException("Cannot open the schema registry lock [{$path}]: ".(error_get_last()['message'] ?? 'unknown error'));
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException("Cannot lock the schema registry [{$path}].");
            }

            $this->lockDepth++;
            try {
                return $critical($this);
            } finally {
                $this->lockDepth--;
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /** True for an identical artifact already in place; a different one is a {@see SchemaRegistryConflict}. */
    private function alreadyRegistered(string $id, string $incoming): bool
    {
        $existing = $this->get($id);
        if ($existing === null) {
            return false;
        }

        $stored = SchemaFingerprint::of($existing);
        if ($stored === $incoming) {
            return true; // Idempotent re-publish of the identical shape.
        }

        throw new SchemaRegistryConflict($id, $stored, $incoming);
    }

    /**
     * Every request and worker builds and reads this registry, so an artifact is read while it may be written.
     * `file_put_contents` on the artifact's own path creates (or truncates) it BEFORE its bytes land: a reader in
     * that window gets an empty or half-written file, `get()` answers null for a schema being registered, and a
     * second writer with another shape slips past the frozen-schema guard above. So the bytes go to a temporary
     * file in the SAME directory (a rename is atomic only within one filesystem) and the temporary file is renamed
     * over the path: a reader sees no artifact or the whole one. The temporary name starts with a dot and does not
     * end in `.schema.json`, so {@see ids()} and any `*.schema.json` glob never list it. A write that does not
     * complete leaves nothing behind and throws, where it used to leave an empty artifact.
     *
     * The rename puts the TEMPORARY file in place, mode and all, so an artifact being replaced (its file is there
     * but does not decode, so `get()` was null) would take the umask's mode over whatever a host had set. The
     * temporary file is given the artifact's mode first. A new artifact keeps what `file_put_contents` gives the
     * temporary file, `0666 & ~umask()`: exactly what the in-place write gave it.
     */
    protected function writeAtomically(string $path, string $contents): void
    {
        $temporary = dirname($path).'/.'.basename($path).'.'.bin2hex(random_bytes(6)).'.tmp';
        clearstatcache(true, $path);
        $mode = @fileperms($path);

        if (@file_put_contents($temporary, $contents) !== strlen($contents)
            || ($mode !== false && ! @chmod($temporary, $mode & 07777))
            || ! @rename($temporary, $path)) {
            $reason = error_get_last()['message'] ?? 'unknown error';
            @unlink($temporary);

            throw new RuntimeException("Cannot write the schema artifact [{$path}]: {$reason}");
        }
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
