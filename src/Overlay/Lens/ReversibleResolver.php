<?php

namespace Schemastud\DataSchemas\Overlay\Lens;

// The bidirectional counterpart to the forward-only overlay fold. Where the
// OverlayStack folds canonical→result one way, this resolves a *record's*
// nullable facade-ref both ways:
//
//   - `get(assoc, canonical)`               — canonical → rendering
//   - `put(assoc, rendering, priorCanonical)` — rendering → canonical
//
// A null association is the free case: identity both ways, so a record with no
// declared lens round-trips byte-identical — exactly today's behaviour. `put`
// reconstructs dropped state from the retained prior canonical, and from the
// association's complement (deriving one from a complementing lens when the slot
// is empty). Fidelity is a claim, not a guarantee: `certify()` proves it against
// representative samples and downgrades a law-breaking lens to `Lossy` rather
// than letting it corrupt content.
class ReversibleResolver
{
    // canonical → rendering. Null association = plain identity embed. When the
    // association carries a stored complement and its lens knows how to re-apply
    // one, the rendering-private state is restored on the way out — closing the
    // symmetric-with-complement loop.
    public function get(?LensAssociation $assoc, mixed $canonical): mixed
    {
        if ($assoc === null) {
            return $canonical;
        }

        $rendering = $assoc->lens->get($canonical);

        if ($assoc->complement !== null && $assoc->lens instanceof PrivateStateLens) {
            $rendering = $assoc->lens->applyComplement($rendering, $assoc->complement);
        }

        return $rendering;
    }

    // rendering → canonical, reconstructing from the retained prior canonical
    // (+ complement). Null association = identity: the rendering *is* the
    // canonical.
    public function put(?LensAssociation $assoc, mixed $rendering, mixed $priorCanonical): mixed
    {
        if ($assoc === null) {
            return $rendering;
        }

        $complement = $assoc->complement;

        if ($complement === null && $assoc->lens instanceof ComplementingLens) {
            $complement = $assoc->lens->complementOf($rendering, $priorCanonical);
        }

        return $assoc->lens->put($rendering, $priorCanonical, $complement);
    }

    // Prove the fidelity claim. A lens already tagged `Lossy` is already honest
    // and returned unchanged. A `LosslessEligible` lens is exercised against the
    // supplied samples; if either law fails on any sample, the association is
    // downgraded to `Lossy`. Rendering samples are `[editedRendering,
    // priorCanonical]` pairs for the PutGet check.
    //
    // @param  mixed[]  $canonicalSamples
    // @param  array{0: mixed, 1: mixed}[]  $renderingSamples
    public function certify(LensAssociation $assoc, array $canonicalSamples, array $renderingSamples = []): LensAssociation
    {
        if (! $assoc->isLosslessEligible()) {
            return $assoc;
        }

        foreach ($canonicalSamples as $canonical) {
            if (! LensLaws::getPut($assoc->lens, $canonical, $assoc->complement)) {
                return $assoc->downgraded();
            }
        }

        foreach ($renderingSamples as [$rendering, $priorCanonical]) {
            if (! LensLaws::putGet($assoc->lens, $rendering, $priorCanonical, $assoc->complement)) {
                return $assoc->downgraded();
            }
        }

        return $assoc;
    }
}
