<?php

namespace Schemastud\DataSchemas\Lifecycle;

use LogicException;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;

/**
 * A read-only, first-hit-wins chain over several {@see SchemaRegistry} tiers (beam-facade ticket 82).
 *
 * Deliberately MINIMAL, and deliberately not beam's `BeamSchemaRegistry`. That class is the richer
 * thing — named tiers, config-ordered sources, registration — and it lives in `splicewire/laravel-beam`,
 * which this foundation-tier package cannot import (the wall ticket 34/35 hit from the other side).
 * What the public door needs is only the composition itself, so that is all this is.
 *
 * Tiers are LAZY: {@see FilesystemSchemaRegistry}'s constructor `mkdir`s its directory, so eager
 * construction would silently create a directory for every configured path — including a typo'd one,
 * which would then read as an empty-but-present tier rather than as a misconfiguration.
 *
 * `register()` throws. The served chain is a door, not a store: an artifact reaches it by being
 * frozen into one of the directories it reads, never by being written through it.
 */
class ChainedSchemaRegistry implements SchemaRegistry
{
    /** @var array<int, SchemaRegistry|null> */
    protected array $resolved = [];

    /**
     * @param  array<int, callable(): SchemaRegistry>  $factories
     */
    public function __construct(
        protected array $factories,
    ) {}

    /**
     * Compose a chain over a list of directories, in order.
     *
     * @param  array<int, string>  $directories
     */
    public static function overDirectories(array $directories): self
    {
        return new self(array_map(
            fn (string $dir) => fn () => new FilesystemSchemaRegistry($dir),
            array_values($directories),
        ));
    }

    public function register(array $schema): void
    {
        throw new LogicException(
            'The served schema chain is read-only. Register the artifact into one of the directories it reads.'
        );
    }

    public function get(string $id): ?array
    {
        foreach (array_keys($this->factories) as $i) {
            $found = $this->tier($i)->get($id);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    public function has(string $id): bool
    {
        foreach (array_keys($this->factories) as $i) {
            if ($this->tier($i)->has($id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every `$id` across every tier, de-duplicated and sorted.
     *
     * NOTE the cost, which the door never pays and a conformance audit must: the filesystem tier
     * globs and `json_decode`s every file on every call, uncached. Enumerate once and hold it.
     */
    public function ids(): array
    {
        $ids = [];
        foreach (array_keys($this->factories) as $i) {
            foreach ($this->tier($i)->ids() as $id) {
                $ids[$id] = true;
            }
        }

        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    protected function tier(int $i): SchemaRegistry
    {
        return $this->resolved[$i] ??= ($this->factories[$i])();
    }
}
