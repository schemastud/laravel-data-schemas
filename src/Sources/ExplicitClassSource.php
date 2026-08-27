<?php

namespace Schemastud\DataSchemas\Sources;

use Schemastud\DataSchemas\Actions\DiscoverDataClassesAction;
use Schemastud\DataSchemas\Collectors\Collector;

/**
 * One named class, and nothing else — the source `schemas:generate --class=` builds on the spot.
 *
 * It is deliberately NOT registered. `--class` is an operator override, and an override REPLACES the
 * {@see SchemaProjectionRegistry} for that one run rather than being registered into it or filtering
 * its union. Two reasons, both of which are about not changing what the flag has always meant:
 *
 * - **Filtering the union would narrow the flag.** `--class` today generates a class whether or not
 *   anything discovers it — it works for a class under no configured path at all, which is most of
 *   why an operator reaches for it. Post-filtering the registry would silently turn it into "generate
 *   this class IF some source already knows it", so the flag would start reporting *nothing found* for
 *   exactly the case it exists to serve.
 * - **Registering it would leak a run's argument into a host-lifetime declaration.** The registry
 *   answers "where does this host project from"; a flag on one invocation is not an answer to that.
 *
 * The consequence, stated plainly because it IS the semantics: when a host has registered a second
 * source and the operator passes `--class`, that second source is not consulted. The run generates the
 * one named class. `--path` behaves the same way (an ad-hoc {@see PathScanSource} over just that path)
 * and, since it already replaced `auto_discover_types` wholesale, replacing the registry is the same
 * rule one tier out.
 *
 * The collector filter still applies — a `--class` that is not a `Data` subclass contributes nothing,
 * exactly as before — because that is not a narrowing, it is this package's definition of what it can
 * project at all.
 */
class ExplicitClassSource implements SchemaSource
{
    /**
     * @param  class-string|string  $className
     * @param  array<string, mixed>|null  $config  an explicit `data-schemas` config; null reads the host's
     */
    public function __construct(protected string $className, protected ?array $config = null) {}

    public function classes(): array
    {
        $config = $this->config ?? (array) config('data-schemas', []);

        $config['auto_discover_types'] ??= [];

        return (new DiscoverDataClassesAction($config, Collector::fromConfig($config)))
            ->execute(className: $this->className);
    }
}
