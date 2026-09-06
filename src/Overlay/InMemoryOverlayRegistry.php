<?php

namespace Schemastud\DataSchemas\Overlay;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;

// In-memory default registry. A key may carry more than one overlay document
// (registrations append); documentsFor concatenates them in key order. Good
// enough as the container default and for tests; a host may bind a persistent
// implementation.
#[IsRegistry(
    root: 'schemas.overlays',
    onDuplicate: OnDuplicate::Admit,
    description: 'DataOverlay documents by key — the forward-only override/merge/unset deltas laid over a canonical. Declared on the default implementation rather than on the DataOverlayRegistry contract, because a root is owned by whatever actually holds the keyspace. Admit because a key may legitimately carry several documents: stackFor() concatenates them and folds in registration order, last write at a JSONPath target winning.',
    order: 31,
)]
class InMemoryOverlayRegistry implements DataOverlayRegistry
{
    /** @var array<string, OverlayDocument[]> */
    protected array $documents = [];

    public function register(string $key, OverlayDocument|array $document): static
    {
        $this->documents[$key][] = $document instanceof OverlayDocument
            ? $document
            : OverlayDocument::fromArray($document);

        return $this;
    }

    public function has(string $key): bool
    {
        return ! empty($this->documents[$key]);
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
