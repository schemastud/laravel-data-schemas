<?php

namespace Schemastud\DataSchemas\Lifecycle;

use Illuminate\Support\Facades\File;
use ReflectionClass;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Spatie\LaravelData\Data;

/**
 * Build-time drift guard (slice 14).
 *
 * Discovers the app's versioned ({@see SchemaIdentity}) Data classes, projects
 * each to a JSON Schema via {@see JsonSchemaGenerator}, and compares its current
 * structural {@see SchemaFingerprint} against the latest FROZEN artifact recorded
 * under the same absolute versioned `$id` in a {@see SchemaRegistry}.
 *
 * Drift = the class's `$id` already has a frozen artifact, but the current
 * fingerprint differs from the frozen one. That is a shape change that was NOT
 * accompanied by a version bump (a bump would mint a fresh `$id`, which has no
 * frozen artifact yet and so is accepted). The remedy is to bump the version and
 * freeze (register a migration), which the failure message states.
 *
 * This is a CLI / test-suite gate (`schema:check`, plus a Pest test). It is NOT
 * wired into runtime boot — CI runs it explicitly.
 *
 * ## Lifted out of `splicewire/tower` — beam-facade ticket 176, 2026-08-27
 *
 * This class was `Splicewire\Tower\Schema\SchemaDriftGuard` and imported **zero**
 * `Splicewire\Tower\*` symbols — it lived in tower by accident of authorship, not coupling.
 * Measured 2026-08-27 by enumerating `~/Herd/*` on disk with the three starter symlinks
 * resolved: **21 real roots carry an `artisan`**, tower is installed at **2** of them, and
 * `schemastud/laravel-data-schemas` — the package that MINTS the versioned `$id` this guard
 * exists to answer for — reaches **15**. So the obligation ticket 107 ruled (a host must
 * answer for every versioned class it installs) had an instrument that could not reach 19 of
 * the 21 roots that incur it. `Splicewire\Tower\Schema\SchemaDriftGuard` survives as a
 * `class_alias` shim.
 */
class SchemaDriftGuard
{
    public function __construct(
        protected SchemaRegistry $registry,
        protected JsonSchemaGenerator $generator,
        /** @var list<string> absolute directories to scan for SchemaIdentity Data classes */
        protected array $scanPaths,
    ) {}

    /**
     * Construct the guard against the app's frozen-artifacts store.
     *
     * Scan paths come from `data-schemas.scan_paths`, falling back to `app/`.
     *
     * ⚠️ **The fallback is why this guard was checking NOTHING** (beam-facade ticket 152). It scanned
     * `app_path()` only, and every `SchemaIdentity` class in this estate has since moved into a
     * PACKAGE — so `schema:check` reported *"Checked 0 versioned schema(s); no drift detected"* and
     * passed, at a host with 74 versioned classes on disk. A green guard that discovered nothing is
     * the estate's recurring *instrument that reports success by not running*, and it also left those
     * classes UNFROZEN: their `$id`s minted fine and then 404'd at the schema door, because nothing
     * had ever written an artifact for them.
     *
     * A host therefore declares the directories that carry its versioned schemas — its own `app/`
     * plus whichever package `Data` trees it is the publishing authority for. Config-driven rather
     * than scanned-by-discovery on purpose: which packages a host answers for is a host fact, and
     * `base_uri` (the authority stamped into every `$id` produced here) is declared the same way.
     *
     * @param  list<string>|null  $scanPaths  explicit override, bypassing config.
     */
    public static function forApp(?string $frozenDirectory = null, ?array $scanPaths = null): self
    {
        return new self(
            new FilesystemSchemaRegistry($frozenDirectory ?? static::defaultFrozenDirectory()),
            new JsonSchemaGenerator(config('data-schemas', [])),
            $scanPaths ?? static::configuredScanPaths(),
        );
    }

    /**
     * The declared scan paths, filtered to those that exist — a host naming a package tree it does
     * not currently install must not make the guard throw.
     *
     * @return list<string>
     */
    public static function configuredScanPaths(): array
    {
        $declared = (array) config('data-schemas.scan_paths', []);

        $paths = array_values(array_filter(
            array_map('strval', $declared),
            static fn (string $p): bool => is_dir($p),
        ));

        return $paths === [] ? [app_path()] : $paths;
    }

    /**
     * The default frozen-artifacts store: committed JSON under
     * `resources/schemas/lifecycle`.
     */
    public static function defaultFrozenDirectory(): string
    {
        return resource_path('schemas/lifecycle');
    }

    /**
     * Sweep every discovered versioned class and report drift.
     */
    public function check(): SchemaDriftResult
    {
        $checked = [];
        $drifted = [];
        $unfrozen = [];

        foreach ($this->discover() as $class) {
            $checked[] = $class;

            $schema = $this->project($class);
            $id = $schema['$id'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }

            $current = SchemaFingerprint::of($schema);
            $frozen = $this->registry->get($id);

            if ($frozen === null) {
                // No frozen artifact for this $id yet: a new or version-bumped
                // class. Accepted; `schema:freeze` will record it.
                $unfrozen[] = $class;

                continue;
            }

            $frozenFingerprint = SchemaFingerprint::of($frozen);
            if ($frozenFingerprint !== $current) {
                $drifted[] = new SchemaDriftEntry(
                    class: $class,
                    id: $id,
                    frozenFingerprint: $frozenFingerprint,
                    currentFingerprint: $current,
                    reason: sprintf(
                        '%s diverged from its frozen schema (%s) without a version bump. '
                        .'Bump %s::schemaVersion() and register a migration, then run `schema:freeze`.',
                        $class,
                        $id,
                        $class,
                    ),
                );
            }
        }

        return new SchemaDriftResult($checked, $drifted, $unfrozen);
    }

    /**
     * Freeze the current projected schema for every discovered versioned class
     * into the frozen-artifacts store. Write-once: an `$id` already present with a
     * DIFFERENT fingerprint throws {@see SchemaRegistryConflict}
     * (you must bump the version first); the same fingerprint is an idempotent
     * no-op. Returns the `$id`s newly written.
     *
     * `$only` narrows the write to a subset of the discovered classes without narrowing DISCOVERY
     * (beam-facade ticket 176). The two must stay separate: `schema:freeze --scope=` reports the wide
     * population and writes the narrow one, and a guard that narrowed discovery instead could not
     * report what it was skipping — which is the difference between a scoped freeze and an instrument
     * that reports success by not running.
     *
     * @param  list<class-string>|null  $only  null freezes every discovered class
     * @return list<string>
     */
    public function freeze(?array $only = null): array
    {
        $frozen = [];
        $allowed = $only === null ? null : array_flip($only);

        foreach ($this->discover() as $class) {
            if ($allowed !== null && ! isset($allowed[$class])) {
                continue;
            }

            $schema = $this->project($class);
            $id = $schema['$id'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }

            $existed = $this->registry->has($id);
            $this->registry->register($schema);
            if (! $existed) {
                $frozen[] = $id;
            }
        }

        return $frozen;
    }

    /**
     * Project a class to its JSON Schema array (with the absolute versioned `$id`).
     *
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    public function project(string $class): array
    {
        return $this->generator->generate(new ReflectionClass($class));
    }

    /**
     * Discover every {@see SchemaIdentity} Data class under the scan paths.
     *
     * @return list<class-string<Data&SchemaIdentity>>
     */
    public function discover(): array
    {
        $classes = [];

        foreach ($this->scanPaths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $class = $this->classFromFile($file->getPathname());
                if ($class === null || isset($classes[$class])) {
                    continue;
                }

                if (! class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);
                if ($reflection->isAbstract()) {
                    continue;
                }
                if (! $reflection->isSubclassOf(Data::class)) {
                    continue;
                }
                if (! $reflection->implementsInterface(SchemaIdentity::class)) {
                    continue;
                }

                $classes[$class] = true;
            }
        }

        return array_keys($classes);
    }

    /**
     * Resolve the fully-qualified class name declared in a PHP file via its
     * `namespace` + `class` tokens. Returns null if no class is declared.
     */
    protected function classFromFile(string $path): ?string
    {
        $contents = (string) file_get_contents($path);

        $namespace = '';
        if (preg_match('/^\s*namespace\s+([^;]+);/m', $contents, $m) === 1) {
            $namespace = trim($m[1]);
        }

        if (preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $contents, $m) !== 1) {
            return null;
        }

        $class = $m[1];

        return $namespace !== '' ? $namespace.'\\'.$class : $class;
    }
}
