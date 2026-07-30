<?php

namespace Schemastud\DataSchemas\Overlay\Lens;

// The get/put mechanism of a facade — the bidirectional generalisation of the
// forward-only overlay fold. It is the ADR-0038 `project()`/`hydrate()` pair
// made reversible:
//
//   - `get`  (total)       — project the canonical outward to a rendering.
//   - `put`  (best-effort) — fold a rendering edit back onto the canonical,
//                            reconstructing anything the projection dropped from
//                            the *retained prior canonical* (and, when supplied,
//                            a `complement` holding rendering-private state).
//
// Well-behavedness (GetPut: `put(get(S), S) = S`, PutGet: `get(put(V, S)) = V`)
// is a *design obligation*, not a guarantee this interface enforces — the
// resolver proves or downgrades it. A lens that cannot satisfy the laws is
// honestly `Fidelity::Lossy`, never a silent corruptor.
interface DirectedLens
{
    // Project the canonical value outward into its rendering. Total: every
    // canonical maps to exactly one rendering.
    public function get(mixed $canonical): mixed;

    // Fold a (possibly edited) rendering back onto the canonical. Best-effort:
    // it may lean on the retained prior canonical and an optional complement to
    // reconstruct state the rendering does not itself carry.
    public function put(mixed $rendering, mixed $priorCanonical, mixed $complement = null): mixed;
}
