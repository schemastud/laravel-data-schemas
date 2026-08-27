<?php

namespace Schemastud\DataSchemas\Actions;

use ReflectionClass;
use Rushing\Popcorn\Laravel\PopcornManager;
use Schemastud\DataSchemas\Collectors\Collector;

/**
 * Enumerate the `Data` classes a generate run should cover.
 *
 * File→class derivation is popcorn's, not this package's: it used to hand-roll
 * `preg_match('/class\s+(\w+)/')`, which matches the FIRST "class" in the file — routinely the word
 * inside a docblock ("a first-class Capability", "the class publishes…"). The derived name did not
 * exist, `class_exists()` said false, and the file was silently skipped. Measured across
 * every host's `app/Data`: twelve genuine `Data` subclasses never generated a schema, with no error
 * anywhere. {@see \Rushing\Popcorn\Discovery\AttributedClassScanner} tokenizes instead, and is reached
 * through {@see PopcornManager::classesIn()} because ticket 07 D10 makes the manager the only door onto
 * it.
 *
 * What stays here is the FILTER — {@see Collector::canCollect()}, i.e. `isSubclassOf(Data::class)` plus
 * the configured namespace fnmatch. Popcorn answers "what classes are under these paths"; which of them
 * this package cares about is this package's vocabulary.
 */
class DiscoverDataClassesAction
{
    public function __construct(
        protected array $config,
        protected array $collectors,
        protected ?PopcornManager $popcorn = null
    ) {}

    public function execute(?string $path = null, ?string $className = null): array
    {
        // If specific class provided, return just that class
        if ($className) {
            if (! class_exists($className)) {
                throw new \InvalidArgumentException("Class {$className} does not exist");
            }

            $reflection = new ReflectionClass($className);

            return $this->canCollect($reflection) ? [$reflection] : [];
        }

        // Otherwise scan paths
        $paths = $path ? [$path] : $this->config['auto_discover_types'];
        $classes = [];

        foreach ($this->popcorn()->classesIn(array_values($paths)) as $discovered) {
            $reflection = new ReflectionClass($discovered);

            if ($this->canCollect($reflection)) {
                $classes[] = $reflection;
            }
        }

        return $classes;
    }

    /**
     * Resolved lazily so the action stays constructible with two arguments, as its callers — the
     * {@see \Schemastud\DataSchemas\Sources\SchemaSource} implementations that wrap it — build it.
     * `schemas:generate` no longer news one directly: it asks {@see
     * \Schemastud\DataSchemas\Sources\SchemaProjectionRegistry} instead, so a scan is one registered
     * source among however many the host has rather than the command's only universe.
     */
    protected function popcorn(): PopcornManager
    {
        return $this->popcorn ??= app(PopcornManager::class);
    }

    protected function canCollect(ReflectionClass $class): bool
    {
        foreach ($this->collectors as $collector) {
            if ($collector instanceof Collector && $collector->canCollect($class)) {
                return true;
            }
        }

        return false;
    }
}
