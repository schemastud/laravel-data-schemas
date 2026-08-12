<?php

namespace Schemastud\DataSchemas\Overlay\Lens;

use Closure;

// The samples a registration submits so its fidelity can be *certified* instead
// of believed — the representative values `LensLaws` are actually exercised
// against by `ReversibleResolver::certify()`.
//
//   - `canonicalSamples`  — canonical values, checked with GetPut: put(get(S), S) = S.
//   - `renderingSamples`  — `[editedRendering, priorCanonical]` pairs, checked
//                           with PutGet: get(put(V, S)) = V.
//
// **An empty evidence set is not a passing one.** The laws over zero samples are
// vacuously true, so treating "submitted nothing" as "survived everything" would
// hand a lossless verdict to any lens that simply declined to be tested — the
// exact self-description certification exists to refuse. `empty()` is therefore a
// hard fail in `LensRegistration::certifiedFidelity()`, not a skip.
//
// Both lists may be supplied as a `Closure` returning the list. Registration
// happens in a service provider's `boot()`, where building a representative
// canonical can be expensive (or need bindings that are not up yet); deferring
// keeps joining the registry cheap enough that no one is tempted to skip it, and
// the samples are only built when something actually asks for a verdict.
//
// This is the mechanism-tier twin of `splicewire/laravel-composition-engine`'s
// `Renderings\ReversibilityProof`, which carries exactly this pair for the
// rendering seam. A rendering that also registers its lens adapts in one
// expression: `new LensEvidence($proof->canonicalSamples, $proof->renderingSamples)`.
class LensEvidence
{
    /** @var list<mixed>|null */
    private ?array $canonical = null;

    /** @var list<array{0: mixed, 1: mixed}>|null */
    private ?array $rendering = null;

    /**
     * @param  list<mixed>|Closure(): list<mixed>  $canonicalSamples
     * @param  list<array{0: mixed, 1: mixed}>|Closure(): list<array{0: mixed, 1: mixed}>  $renderingSamples
     */
    public function __construct(
        private array|Closure $canonicalSamples = [],
        private array|Closure $renderingSamples = [],
    ) {}

    /** @return list<mixed> */
    public function canonicalSamples(): array
    {
        return $this->canonical ??= array_values($this->resolve($this->canonicalSamples));
    }

    /** @return list<array{0: mixed, 1: mixed}> */
    public function renderingSamples(): array
    {
        return $this->rendering ??= array_values($this->resolve($this->renderingSamples));
    }

    // Nothing was exercised, so nothing is certified. Lazy lists are built to
    // answer this: "I promised samples and produced none" is the empty case too.
    public function empty(): bool
    {
        return $this->canonicalSamples() === [] && $this->renderingSamples() === [];
    }

    /**
     * @param  array<mixed>|Closure  $samples
     * @return array<mixed>
     */
    private function resolve(array|Closure $samples): array
    {
        return is_array($samples) ? $samples : (array) ($samples)();
    }
}
