<?php

namespace Schemastud\DataSchemas\Overlay\Lens;

// The reserved arm of the lens contract: a lens whose rendering carries private
// state absent from the canonical (the symmetric-with-complement case). Such a
// lens can extract that private state as a *complement* on the way out, so the
// return trip has everything it needs to reconstruct the rendering losslessly.
//
// This is the `complement` slot the ops already imply, made explicit: `override`
// is invertible only if the prior value is retained; `unset` needs the removed
// value. A near-bijective lens implements only DirectedLens and leaves the slot
// null; only an earned-lossless projection (e.g. music-profile ↔ OTIO) reaches
// for this.
interface ComplementingLens extends DirectedLens
{
    // Extract the rendering-private state not represented in the canonical, to
    // be stored as the association's complement and fed back to `put()` on the
    // return trip. Returns null when the rendering holds nothing private.
    public function complementOf(mixed $rendering, mixed $canonical): mixed;
}
