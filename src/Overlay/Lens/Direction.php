<?php

namespace Schemastud\DataSchemas\Overlay\Lens;

// Which side of the association is canonical — the fixed point the round-trip is
// measured against. A lens is always canonical ↔ rendering; `direction` records
// the orientation so a resolver can tell `get` (project the canonical outward to
// the rendering) from `put` (fold a rendering edit back onto the canonical)
// without inferring it. `CanonicalToRendering` is the natural orientation: the
// association's `id` names the canonical, its `locator` addresses the rendering,
// and `get` runs id → locator.
enum Direction: string
{
    case CanonicalToRendering = 'canonical-to-rendering';

    case RenderingToCanonical = 'rendering-to-canonical';
}
