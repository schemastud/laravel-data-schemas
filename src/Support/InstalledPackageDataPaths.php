<?php

namespace Schemastud\DataSchemas\Support;

/**
 * Every installed package's `src/Data` tree — the default population for `data-schemas.scan_paths`
 * (beam-facade ticket 107).
 *
 * ## The ruling this implements
 *
 * Ticket 64 ruled that an `$id`'s authority is **the origin that serves this copy**, and 107 ruled
 * the consequence the estate had been treating as a defect: `$id` divergence across hosts is
 * CORRECT. One `Data` class legitimately mints N `$id`s across N hosts, and two hosts holding
 * byte-identical system schemas under different names is the design working, not drift.
 *
 * What that ruling *obliges* is this class. If a host stamps its own authority onto every versioned
 * class it installs — which {@see \Schemastud\DataSchemas\Generators\JsonSchemaGenerator::versionedId()}
 * does, in memory, for the OpenAPI spec and for `laravel-frame`'s live schema route — then the host
 * must actually ANSWER for all of them, or it is minting `$id`s that 404 at its own door. Ticket 82
 * turned an `$id` into a fetch key; a promise nothing keeps is the one shape 64's semantics cannot
 * tolerate.
 *
 * ## Why the default had to move into the package
 *
 * `scan_paths` arrived with ticket 152 and had **no package default**, so it was purely host-declared.
 * Measured 2026-08-27: exactly ONE root in the estate had ever declared it, and every other root fell
 * through {@see \Schemastud\DataSchemas\Lifecycle\SchemaDriftGuard::configuredScanPaths()}'s `app/` fallback —
 * which discovers nothing, because every `SchemaIdentity` class in this estate now lives in a package
 * (54 of them, across 9 packages). The guard was green estate-wide by not running, and the classes it
 * could not see were never frozen, so their `$id`s 404'd exactly as above.
 *
 * 152 also recorded the opposite policy in the flagship's config — *"do not add a package this host
 * merely consumes: freezing its schemas here would mint them under THIS origin and make this host
 * answer for someone else's shapes."* That caution is **superseded by 107**: answering for a shape at
 * your own origin is precisely what an `$id` means under 64, so the thing it warned against is the
 * intended behaviour. 152 was a task ticket clearing a codegen failure and never argued the point;
 * 107 is the decision ticket that owns it.
 *
 * ## Why there is no family-vendor list, which is the part that looks wrong and is not
 *
 * Scanning EVERY installed package reads as over-broad. It is not, because the consumer filters to
 * classes implementing {@see \Schemastud\DataSchemas\Contracts\SchemaIdentity} — an interface THIS
 * package declares. A third-party dependency will never implement it, so the narrowing is inherent in
 * the contract instead of maintained in a vendor list that would silently drift the moment a vendor
 * was added or renamed. It also preserves 64's rule that this package stays ignorant of which
 * authority anyone claims — it is now equally ignorant of whose packages count.
 *
 * ## Substrate
 *
 * `vendor/composer/installed.json`, deliberately — never a `vendor/` directory walk. This estate
 * symlinks family packages into `vendor/`, where `grep -r` cannot follow and `-R` cannot finish
 * because the linked packages nest their own `vendor/`; `installed.json` is the one piece of that
 * substrate that survives contact, and it is what the published-migration-drift audit already reads.
 */
class InstalledPackageDataPaths
{
    /**
     * The `src/Data` trees of every package installed at `$basePath`.
     *
     * Returns empty — never throws — when the root has no `installed.json`. A package's own testbench
     * harness is the ordinary case, and discovery that takes boot down with it would be a check whose
     * answer depends on the host doing exactly what this estate forbids.
     *
     * @return list<string>
     */
    public static function discover(?string $basePath = null): array
    {
        $basePath = $basePath ?? (function_exists('base_path') ? base_path() : getcwd());

        $vendorDirectory = rtrim((string) $basePath, '/').'/vendor';
        $installedJson = $vendorDirectory.'/composer/installed.json';

        if (! is_file($installedJson)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($installedJson), true);

        if (! is_array($decoded)) {
            return [];
        }

        // Composer 2 nests the list under `packages`; Composer 1 wrote a bare top-level array. Both
        // are read rather than assumed, because guessing wrong here returns a confident empty list.
        $packages = $decoded['packages'] ?? $decoded;

        return is_array($packages)
            ? self::fromInstalledPackages($packages, $vendorDirectory)
            : [];
    }

    /**
     * The pure core: package entries in, existing absolute `src/Data` directories out.
     *
     * Sorted and de-duplicated by RESOLVED path. The de-dupe is correctness rather than tidiness —
     * the co-dev overlay symlinks a family package into `vendor/` while composer may also list it
     * under an alias, and a tree reached twice would be frozen twice, the second pass comparing an
     * artifact against itself under a second path.
     *
     * @param  array<int|string, mixed>  $packages  raw `installed.json` entries
     * @param  string  $vendorDirectory  absolute path to the root's `vendor/`
     * @return list<string>
     */
    public static function fromInstalledPackages(array $packages, string $vendorDirectory): array
    {
        return array_values(array_keys(self::mapFromInstalledPackages($packages, $vendorDirectory)));
    }

    /**
     * The same discovery, keyed by resolved `src/Data` directory to the composer package name that
     * owns it (beam-facade ticket 176).
     *
     * The paths alone answer "what do I scan"; `schema:freeze`'s report-before-mutate step has to
     * answer a second question — *whose shapes am I about to make this host answer for, forever* —
     * and 107's flagship pass had to derive that by hand. A write-once mutation whose report cannot
     * name its owners is a report you cannot act on.
     *
     * @param  array<int|string, mixed>  $packages  raw `installed.json` entries
     * @param  string  $vendorDirectory  absolute path to the root's `vendor/`
     * @return array<string, string> resolved absolute `src/Data` path => `vendor/name`
     */
    public static function mapFromInstalledPackages(array $packages, string $vendorDirectory): array
    {
        $vendorDirectory = rtrim($vendorDirectory, '/');
        $found = [];

        foreach ($packages as $package) {
            if (! is_array($package)) {
                continue;
            }

            $name = $package['name'] ?? null;
            $installPath = $package['install-path'] ?? null;

            if (! is_string($name) || $name === '' || ! is_string($installPath) || $installPath === '') {
                continue;
            }

            // `install-path` is written relative to `vendor/composer/`, not to `vendor/`.
            $candidate = str_starts_with($installPath, '/')
                ? $installPath
                : $vendorDirectory.'/composer/'.$installPath;

            $dataDirectory = realpath($candidate.'/src/Data');

            if ($dataDirectory === false || ! is_dir($dataDirectory)) {
                continue;
            }

            $found[$dataDirectory] = $name;
        }

        ksort($found);

        return $found;
    }

    /**
     * `discover()`'s map form: resolved `src/Data` path => owning `vendor/name`.
     *
     * Same empty-not-throw contract as {@see discover()}.
     *
     * @return array<string, string>
     */
    public static function owners(?string $basePath = null): array
    {
        $basePath = $basePath ?? (function_exists('base_path') ? base_path() : getcwd());

        $vendorDirectory = rtrim((string) $basePath, '/').'/vendor';
        $installedJson = $vendorDirectory.'/composer/installed.json';

        if (! is_file($installedJson)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($installedJson), true);

        if (! is_array($decoded)) {
            return [];
        }

        $packages = $decoded['packages'] ?? $decoded;

        return is_array($packages)
            ? self::mapFromInstalledPackages($packages, $vendorDirectory)
            : [];
    }
}
