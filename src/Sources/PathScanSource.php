<?php

namespace Schemastud\DataSchemas\Sources;

use Schemastud\DataSchemas\Actions\DiscoverDataClassesAction;
use Schemastud\DataSchemas\Collectors\Collector;

/**
 * The source this package has always had, now saying so: scan `auto_discover_types`.
 *
 * A thin wrapper over {@see DiscoverDataClassesAction} and nothing more — the file→class walk is
 * popcorn's ({@see \Rushing\Popcorn\Laravel\PopcornManager::classesIn()}) and the Data-subclass filter
 * is the configured {@see Collector} list's. This class exists so that the ONE universe
 * `schemas:generate` has always enumerated is an ENTRY in {@see SchemaProjectionRegistry} rather than
 * an assumption inside a command, which is what makes a second universe contributable beside it.
 *
 * The `$config` constructor argument is also what lets `schemas:generate --path=` build one of these
 * AD HOC, over just that path, and use it INSTEAD of the registry — see {@see ExplicitClassSource} for
 * why an operator override replaces the registry rather than filtering its union.
 *
 * Config is read at `classes()` time, not at construction, for the reason the registry's whole shape
 * turns on: this object is registered in a provider's `boot()`, and a host (or a test) that configures
 * `auto_discover_types` afterwards must be seen. It is the same posture the `Generator` and
 * `SchemaIdResolver` bindings take for the same reason — with the difference that here laziness is
 * inherent to the contract rather than bought by declining to be a singleton.
 */
class PathScanSource implements SchemaSource
{
    /**
     * @param  array<string, mixed>|null  $config  an explicit `data-schemas` config; null reads the host's
     */
    public function __construct(protected ?array $config = null) {}

    public function classes(): array
    {
        $config = $this->config ?? (array) config('data-schemas', []);

        $config['auto_discover_types'] ??= [];

        return (new DiscoverDataClassesAction($config, Collector::fromConfig($config)))->execute();
    }
}
