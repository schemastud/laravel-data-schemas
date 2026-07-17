<?php

namespace Schemastud\DataSchemas\Overlay;

// Maps active overlay keys → overlay documents. A deliberate **sibling** of the
// render-axis WidgetContextRegistry (not merged — the invocation axis and the
// render axis are orthogonal, SPEC 3.1). Resolver + registry are the only
// context-aware pieces; the fold itself stays a pure, context-ignorant fold.
interface DataOverlayRegistry
{
    public function register(string $key, OverlayDocument|array $document): static;

    public function has(string $key): bool;

    /**
     * The overlay documents bound to these keys, concatenated in the given key
     * order (keys with no documents are skipped). Order is preserved so the
     * resolver's declared order becomes the fold order.
     *
     * @param  string[]  $keys
     * @return OverlayDocument[]
     */
    public function documentsFor(array $keys): array;

    /**
     * Assemble the OverlayStack for these keys, in declared order.
     *
     * @param  string[]  $keys
     */
    public function stackFor(array $keys): OverlayStack;
}
