<?php

namespace Schemastud\DataSchemas\Overlay;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\CarriesDeclaration;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;

// In-memory default registry. A key may carry more than one overlay document
// (registrations append); documentsFor concatenates them in key order. Good
// enough as the container default and for tests; a host may bind a persistent
// implementation.
//
// It is DECLARED a popcorn registry, so it also IS one (launch 00, 2026-10-18:
// `popcorn:registries:cache` refused to bake any host carrying this class —
// "do not implement the Registry contract, so nothing can describe them" —
// which blocked a fresh-clone audiostud on every fleet Mac). The kernel surface
// is composed over {@see BasicRegistry} per the contract's own doctrine; the
// exact-key document map stays beside it because the FOLD is exact-key by SPEC
// (a key's documents, in registration order — never a segment-wise subtree,
// which is what the kernel's `matches()` answers) and the kernel keeps no
// exact-key multi-entry read. Both stores are written by the one `register()`,
// append-only, so they cannot drift.
#[IsRegistry(
    root: 'schemas.overlays',
    onKeyDuplicate: OnKeyDuplicate::Admit,
    description: 'DataOverlay documents by key — the forward-only override/merge/unset deltas laid over a canonical. Declared on the default implementation rather than on the DataOverlayRegistry contract, because a root is owned by whatever actually holds the keyspace. Admit because a key may legitimately carry several documents: stackFor() concatenates them and folds in registration order, last write at a JSONPath target winning.',
    order: 31,
)]
class InMemoryOverlayRegistry implements CarriesDeclaration, DataOverlayRegistry, Registry
{
    private BasicRegistry $entries;

    /** @var array<string, OverlayDocument[]> */
    protected array $documents = [];

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);
    }

    /**
     * Satisfies both contracts: {@see DataOverlayRegistry} (a string key and an
     * OverlayDocument|array) and the kernel's {@see Registry} (`$by`/`$ability` ride through).
     * Anything that is not an overlay document is refused by the coercion, loudly.
     */
    public function register(RegistryKey|string $key, mixed $document, ?string $by = null, ?string $ability = null): static
    {
        $document = $document instanceof OverlayDocument
            ? $document
            : OverlayDocument::fromArray((array) $document);

        $this->entries->register($key, $document, $by, $ability);
        $this->documents[(string) $key][] = $document;

        return $this;
    }

    /** Exact-key, as the fold has always read it. */
    public function has(RegistryKey|string $key): bool
    {
        return ! empty($this->documents[(string) $key]);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->entries->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->entries->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->entries->matches($key);
    }

    public function keys(): array
    {
        return $this->entries->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->entries->unfiltered();
    }

    public function declaration(): IsRegistry
    {
        return $this->entries->declaration();
    }

    public function documentsFor(array $keys): array
    {
        $documents = [];

        foreach ($keys as $key) {
            foreach ($this->documents[$key] ?? [] as $document) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    public function stackFor(array $keys): OverlayStack
    {
        return new OverlayStack($this->documentsFor($keys));
    }
}
