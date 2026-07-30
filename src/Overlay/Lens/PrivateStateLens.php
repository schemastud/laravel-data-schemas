<?php

namespace Schemastud\DataSchemas\Overlay\Lens;

// The fully-wired arm of the reserved complement: a complementing lens that can
// also *re-apply* a stored complement onto a freshly-projected rendering, so the
// rendering-private state survives a canonical round-trip. This is what closes
// the symmetric-with-complement loop end-to-end: `complementOf` lifts the
// private state out on save, `applyComplement` puts it back on load. The
// ReversibleResolver drives both — `put` derives/consumes the complement,
// `get` re-applies a stored one — so nothing above the lens has to know.
//
// A near-bijective or asymmetric lens implements plain DirectedLens (or
// ComplementingLens for extraction only) and never needs this.
interface PrivateStateLens extends ComplementingLens
{
    // Re-apply a stored complement onto a projected rendering (the inverse of
    // complementOf). A null complement returns the rendering untouched.
    public function applyComplement(mixed $rendering, mixed $complement): mixed;
}
