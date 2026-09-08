<?php

namespace Schemastud\DataSchemas\Fixtures;

use Closure;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Nested;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\Popcorn\Registries\RegistryNode;
use Rushing\Popcorn\Registries\Superseded;

/**
 * Named fixture states per declared shape, plus that shape's positional before/after hooks.
 *
 * ## One keyspace, two levels — and why that is not a convenience
 *
 *     schemas.fixtures.{shape}            the shape node: defaults + the ordered hook list
 *     schemas.fixtures.{shape}.{state}    one named state
 *
 * States and hooks compose differently and the keyspace has to say so. `PipelineRegistry` states the
 * rule this leans on: its stages *"are not addressable entries of this registry — they have no keys"*,
 * which is exactly why *"the same stage class legitimately appears many times in one pipeline."* A
 * fixture STATE cannot work that way, because a caller names one (`->enterprise()`); a HOOK must work
 * that way, because two hooks are distinguished only by position. So they are different kinds, and
 * they sit at different LEVELS of one keyspace rather than as siblings of each other:
 *
 * | | addressable | composed in |
 * |---|---|---|
 * | states — child nodes | yes | the order the CALLER chains them |
 * | hooks — a positional list on the parent | no | REGISTRATION order |
 *
 * Select the shape node, then compose the fixtures it holds.
 *
 * ## `Supersede` is correct here, and it is not a shrug
 *
 * Package service providers boot before the app's, so **a host is the last registrant by
 * construction** — last-wins is what makes host-overrides-package work with no ceremony, exactly as a
 * container binding does. Refusing a duplicate would break the legitimate case to catch a rarer one,
 * and could not tell them apart anyway: the genuinely accidental collision is peer-vs-peer, two
 * packages claiming one key with no ordering that makes either right. {@see RecordsSupersession} makes
 * that visible instead of fatal, and it is always on wherever `Supersede` is declared.
 *
 * ## Composition, not inheritance
 *
 * {@see BasicRegistry} is held as a field — its own docblock closes the extension door, with
 * `ParticleResourceRegistry` as the exemplar. Everything this class would otherwise hand-roll (keying,
 * tree reads, provenance) is the kernel's.
 *
 * ## `Gated` is deliberately absent — this is a test-time facility
 *
 * The only reader is {@see FixtureFactory} behind `::factory()`, reached from {@see HasFixtures}.
 * Fixtures exist to build objects in a suite, where there is no actor and no request. Gating them
 * would put an authorization decision inside test setup.
 *
 * `Gated` is therefore NOT implemented, and the absence is information rather than an omission
 * (registry-kernel ticket 74). {@see \Rushing\Popcorn\Registries\Authorizer} already states the
 * policy this rests on: tooling reads through the registry's explicit unfiltered accessor under the
 * estate's trusted shell. A console run has no actor to gate against, so an authorizer pushed here
 * would either sit null forever — dead wiring that reads as a working door — or, worse, narrow a
 * maintenance run by whoever happened to be authenticated when it started.
 */
#[IsRegistry(
    root: 'schemas.fixtures',
    entryType: 'mixed',
    onKeyDuplicate: OnKeyDuplicate::Supersede,
    populationRequirement: PopulationRequirement::Optional,
    description: 'named fixture states per declared shape, plus that shape\'s positional before/after hooks. Two levels of ONE keyspace. `schemas.fixtures.{shape}` holds the defaults and the ordered hook list; `schemas.fixtures.{shape}.{state}` is one named state. States are addressable because a caller names them and composes them in CALL order; hooks are positional and compose in REGISTRATION order, which is why they are not siblings. A shape with no shorter declared name keys by `ClassKey`, which carries the namespace so two same-basename classes cannot silently supersede one another.',
)]
class FixtureIndex implements Nested, Registry
{
    private BasicRegistry $store;

    public function __construct()
    {
        $this->store = BasicRegistry::for($this);
    }

    /* ---------------- caller-facing sugar ---------------- */

    /**
     * @param  Closure(): array<string, mixed>  $defaults
     * @param  list<Closure(array<string, mixed>): array<string, mixed>>  $hooks
     */
    public function defineShape(string $shape, Closure $defaults, array $hooks = [], ?string $by = null): static
    {
        $this->store->register("schemas.fixtures.{$shape}", ['defaults' => $defaults, 'hooks' => $hooks], by: $by);

        return $this;
    }

    /** @param  Closure(array<string, mixed>): array<string, mixed>  $state */
    public function defineState(string $shape, string $state, Closure $fn, ?string $by = null): static
    {
        $this->store->register("schemas.fixtures.{$shape}.{$state}", $fn, by: $by);

        return $this;
    }

    public function defaultsFor(string $shape): ?Closure
    {
        $entry = $this->store->tryResolve("schemas.fixtures.{$shape}");

        return is_array($entry) && $entry['defaults'] instanceof Closure ? $entry['defaults'] : null;
    }

    /** @return list<Closure(array<string, mixed>): array<string, mixed>> */
    public function hooksFor(string $shape): array
    {
        $entry = $this->store->tryResolve("schemas.fixtures.{$shape}");

        return is_array($entry) && is_array($entry['hooks'] ?? null) ? array_values($entry['hooks']) : [];
    }

    public function stateFor(string $shape, string $state): ?Closure
    {
        $entry = $this->store->tryResolve("schemas.fixtures.{$shape}.{$state}");

        return $entry instanceof Closure ? $entry : null;
    }

    /**
     * The states of a shape, read from the TREE rather than a hand-kept list — and segment-wise, so
     * `schemas.fixtures.plan` is not a child of `schemas.fixtures.plans`.
     *
     * @return list<string>
     */
    public function statesOf(string $shape): array
    {
        return array_map(
            fn (RegistryKey|string $key): string => array_slice(explode('.', (string) $key), -1)[0],
            $this->store->children("schemas.fixtures.{$shape}"),
        );
    }

    /** @return list<Superseded> what a registration at this key displaced — key, entry, `by` and `sequence` */
    public function supersededAt(RegistryKey|string $key): array
    {
        return $this->store->superseded($key);
    }

    /* ---------------- contract delegation ---------------- */

    public function register(RegistryKey|string $key, mixed $entry, ?string $by = null, ?string $ability = null): static
    {
        $this->store->register($key, $entry, $by, $ability);

        return $this;
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->store->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->store->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->store->tryResolve($key);
    }

    /** @return array<string, mixed> */
    public function matches(RegistryKey|string $key): array
    {
        return $this->store->matches($key);
    }

    /** @return list<RegistryKey> */
    public function keys(): array
    {
        return $this->store->keys();
    }

    /** @return list<RegistryKey> */
    public function children(RegistryKey|string $key): array
    {
        return $this->store->children($key);
    }

    /** @return list<RegistryKey> */
    public function descendants(RegistryKey|string $key): array
    {
        return $this->store->descendants($key);
    }

    public function nodeAt(RegistryKey|string $key): RegistryNode
    {
        return $this->store->nodeAt($key);
    }

    public function unfiltered(): Registry
    {
        return $this->store->unfiltered();
    }
}
