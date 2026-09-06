<?php

namespace Schemastud\DataSchemas\Sources;

use InvalidArgumentException;
use ReflectionClass;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * Where this host's schemas come from — declared, so the answer is enumerable rather than hard-coded.
 *
 * Projection had exactly one source and it was spelled inline: {@see
 * \Schemastud\DataSchemas\Commands\GenerateJsonSchemaCommand} news a {@see
 * \Schemastud\DataSchemas\Actions\DiscoverDataClassesAction} over `config('data-schemas.auto_discover_types')`,
 * and any OTHER way of knowing which classes exist — a particle registry, an explicit manifest, a table
 * of tenant shapes — had nowhere to say so. The cost is not aesthetic: two consumers each enumerating
 * their own universe cannot reconcile, and neither can see that the other exists. This is the seam that
 * lets them share one.
 *
 * ## Entries are SOURCES
 *
 * See {@see SchemaSource} for the argument against the two alternatives (paths, which are one source's
 * private vocabulary; eagerly-resolved class-strings, which are the boot-order trap). The short version
 * is that a source is a QUESTION asked at read time, so registration order cannot become truth.
 *
 * ## Reading sources
 *
 * Select a source by key and enumerate its classes, or use {@see classes()} for the union across
 * every source. Classes have no keys here and may appear in multiple sources.
 *
 * Which is why this class also IMPLEMENTS {@see SchemaSource}: the union of sources is itself one way of
 * knowing which classes a host projects, so `schemas:generate` takes a `SchemaSource` and is handed either
 * this composite (the ordinary run) or an ad-hoc source built from `--class`/`--path`, with one code path
 * for both. The one nonsense that becomes typeable — registering this registry inside itself — is refused
 * in {@see register()}.
 *
 * ## `Supersede`, and `Optional`
 *
 * Supersede because re-registering a source under its own key is how a host REPLACES the shipped path
 * scan with a narrowed one — the same idempotence the estate's `in_array` append guards hand-roll.
 * Optional because a host with no Data classes anywhere is a legitimate host, and emptiness here is a
 * fact about the host, never about the declaration's author.
 */
#[IsRegistry(
    root: 'schemas.projection',
    entryType: SchemaSource::class,
    onDuplicate: OnDuplicate::Supersede,
    description: 'Sources of classes to project as JSON Schemas. Sources are queried at read time so registration does not freeze an incomplete class list. Select a source by key or call classes() for the union. The built-in path-scan source uses auto_discover_types.',
)]
class SchemaProjectionRegistry implements Gated, Registry, SchemaSource
{
    /** @var BasicRegistry<SchemaSource> */
    private BasicRegistry $sources;

    public function __construct()
    {
        $this->sources = BasicRegistry::for($this);
    }

    /**
     * Register a source.
     *
     * A non-{@see SchemaSource} entry is refused LOUDLY rather than coerced, on {@see BasicRegistry}'s
     * own precedent — and here specifically because the near-miss is a class-string, which would look
     * plausible and could never be asked anything. That refusal is legal to be fatal by the estate's
     * bar: the entry's type is something the REGISTRANT could have got right without knowing which host
     * would load it.
     */
    public function register(RegistryKey|string $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        // The composite is itself a `SchemaSource` (see the class docblock), which makes exactly one
        // nonsense entry newly typeable: this registry, inside itself. `classes()` would recurse until
        // the stack ran out, so refuse it where the mistake is still legible.
        if ($entry === $this) {
            throw new InvalidArgumentException(sprintf(
                'A `%s` cannot be registered inside itself (`%s`) — `classes()` would recurse forever.',
                self::class,
                (string) $key,
            ));
        }

        if (! $entry instanceof SchemaSource) {
            throw new InvalidArgumentException(sprintf(
                'A `%s` entry is a %s — an object asked at READ time which classes it contributes; got %s for `%s`. '
                .'A path or a class-string cannot be an entry here: see the contract\'s docblock.',
                self::class,
                SchemaSource::class,
                get_debug_type($entry),
                (string) $key,
            ));
        }

        $this->sources->register($key, $entry, $by, $ability);

        return $this;
    }

    /**
     * The source at `$key`, throwing on a miss — for a key the CODE chose.
     *
     * Its nullable twin is {@see trySource()}, and the pair is published together because the kernel's
     * rule is that a port carrying its own vocabulary must carry BOTH halves across; shipping only the
     * throwing one leaves a caller either importing a kernel exception or paying a double lookup.
     */
    public function source(RegistryKey|string $key): SchemaSource
    {
        return $this->sources->resolve($key);
    }

    /** The same lookup, null on a miss — for a key that came from OUTSIDE (an option, an argument). */
    public function trySource(RegistryKey|string $key): ?SchemaSource
    {
        return $this->sources->tryResolve($key);
    }

    /**
     * Every registered source, in registration order.
     *
     * @return list<SchemaSource>
     */
    public function sources(): array
    {
        // Matched at this registry's OWN declared root, not at `''`. The empty key means the root of
        // the whole tree and only `RegistryIndex` may spell it — every other registry gets
        // `InvalidRegistryKey`. `keys()`-then-resolve would be the same read at twice the lookups.
        return $this->sources->matches($this->sources->declaration()->rootKey());
    }

    /**
     * The union of every source's classes — the whole universe this host projects from.
     *
     * DEDUPED by class name, because two sources knowing about one class is the ordinary case and not a
     * conflict: a particle is also a Data class under a scanned path. First source to name a class wins
     * the slot, so registration order is the tie-break, which is the kernel's ordering guarantee and not
     * a sort invented here.
     *
     * That rule is USER-VISIBLE now that `schemas:generate` reads this union — a class two sources know
     * about is generated once, by whichever source is registered first, and the union's order is the
     * order the command reports in.
     *
     * It does not contradict `onDuplicate: Supersede`, though the two read alike at a glance. They
     * govern different collisions:
     *
     * - **Supersede is about a KEY.** Re-registering `path-scan` REPLACES the source there — last
     *   registration wins — which is how a host swaps the shipped scan for a narrowed one.
     * - **First-wins is about a CLASS**, named by two DIFFERENT keys. Nothing is being replaced; both
     *   sources stay registered and both stay enumerable. The class simply already has a slot.
     *
     * So "last wins" and "first wins" never apply to the same collision, and a host that wants a
     * different class to win reorders registration or supersedes a key — it does not fight a tie-break.
     *
     * @return list<ReflectionClass>
     */
    public function classes(): array
    {
        $classes = [];

        foreach ($this->sources() as $source) {
            foreach ($source->classes() as $class) {
                $classes[$class->getName()] ??= $class;
            }
        }

        return array_values($classes);
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->sources->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->sources->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->sources->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->sources->matches($key);
    }

    public function keys(): array
    {
        return $this->sources->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->sources->unfiltered();
    }

    /** {@see Gated} — the index pushes the host's authorizer down on both edges. */
    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->sources->authorizeWith($authorizer);

        return $this;
    }
}
