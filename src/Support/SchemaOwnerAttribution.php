<?php

namespace Schemastud\DataSchemas\Support;

use ReflectionClass;
use Throwable;

/**
 * Attribute a discovered `SchemaIdentity` class to the composer package that ships it
 * (beam-facade ticket 176).
 *
 * `schema:freeze` writes **write-once** artifacts, and 107's report-before-mutate pass is the step
 * that turned a planned ~20-root freeze into a 1-root freeze. But that report has to answer *whose*
 * shapes a host is about to answer for forever, and at the flagship that grouping — 29
 * `rushing/laravel-commerce`, 2 `splicewire/laravel-beam-threads` — was derived by hand. A count with
 * no owners is a number you cannot act on; this makes the attribution part of the instrument.
 *
 * Attribution is by FILE PATH against `installed.json`'s resolved `src/Data` trees, never by namespace
 * prefix. The estate symlinks family packages into `vendor/`, so a class's namespace says what someone
 * named it and its resolved path says which package this root actually installed — the same distinction
 * that makes `installed.json` the only readable substrate here.
 */
class SchemaOwnerAttribution
{
    /** Classes that resolve to no installed package tree — the host's own `app/`. */
    public const HOST = '(this host)';

    /**
     * Group class names by owning `vendor/name`, sorted by descending count then name.
     *
     * @param  list<class-string>  $classes
     * @param  array<string, string>|null  $owners  path => package, defaults to {@see InstalledPackageDataPaths::owners()}
     * @return array<string, list<class-string>>
     */
    public static function group(array $classes, ?array $owners = null): array
    {
        $owners ??= InstalledPackageDataPaths::owners();

        // Longest path first: a package nested inside another's tree (the co-dev overlay makes this
        // reachable) must win over its container, or every class is attributed to the outer package.
        uksort($owners, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $grouped = [];

        foreach ($classes as $class) {
            $grouped[self::ownerOf($class, $owners)][] = $class;
        }

        uasort($grouped, static fn (array $a, array $b): int => count($b) <=> count($a));

        return $grouped;
    }

    /**
     * The owning package of one class, or {@see HOST}.
     *
     * @param  class-string  $class
     * @param  array<string, string>  $owners  path => package, expected pre-sorted longest-first
     */
    public static function ownerOf(string $class, array $owners): string
    {
        try {
            $file = (new ReflectionClass($class))->getFileName();
        } catch (Throwable) {
            return self::HOST;
        }

        if (! is_string($file) || $file === '') {
            return self::HOST;
        }

        $resolved = realpath($file) ?: $file;

        foreach ($owners as $path => $package) {
            if (str_starts_with($resolved, rtrim($path, '/').'/')) {
                return $package;
            }
        }

        return self::HOST;
    }
}
