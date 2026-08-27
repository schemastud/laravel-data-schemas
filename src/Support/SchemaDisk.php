<?php

namespace Schemastud\DataSchemas\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Where schema files live, as a DISK rather than as absolute paths.
 *
 * The writer used the `File` facade at absolute paths, which is neither fakeable nor
 * swappable — a test either wrote into the real `resource_path('schemas')` or did not
 * test the writer at all, and the estate chose the second. The package now defines a
 * `data-schemas` disk (registered by the provider, rooted at `output_directory`), so
 * `Storage::fake('data-schemas')` is the whole testing story and an S3-backed schema
 * tree is a config change rather than a rewrite.
 *
 * The name is deliberately explicit rather than reusing `local` or `public`: those are
 * a HOST's disks, and a package that writes into them is squatting.
 *
 * PATHS. `PathGenerator` still yields absolute paths — it is a published extension point
 * and hosts implement it — so the disk-relative path is derived here by stripping the
 * configured root. A path outside that root (a `custom_path_generator` that ignores its
 * `$baseDir`) is passed through with its leading separator dropped, which the disk then
 * sandboxes inside its own root: writing outside the schema tree is not a thing this
 * package will do on a host's behalf.
 */
class SchemaDisk
{
    public const DEFAULT = 'data-schemas';

    /** @param array<string, mixed> $config */
    public static function name(array $config): string
    {
        $name = $config['disk'] ?? null;

        return is_string($name) && $name !== '' ? $name : self::DEFAULT;
    }

    /**
     * The disk itself — resolved through the manager, never built ad hoc, so
     * `Storage::fake()` and a host's own definition both take effect.
     *
     * @param  array<string, mixed>  $config
     */
    public static function for(array $config): Filesystem
    {
        return Storage::disk(self::name($config));
    }

    /**
     * An absolute output path expressed relative to the disk root.
     *
     * @param  array<string, mixed>  $config
     */
    public static function relative(string $path, array $config): string
    {
        $root = (string) ($config['output_directory'] ?? '');
        $root = rtrim($root, DIRECTORY_SEPARATOR.'/');

        if ($root !== '' && str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
            $path = substr($path, strlen($root) + 1);
        }

        return ltrim(str_replace('\\', '/', $path), '/');
    }
}
