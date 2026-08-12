<?php

namespace Schemastud\DataSchemas\Overlay\Lens;

use Closure;

// One declared lens, made discoverable: the association itself, the tier that says
// whose truth it is, and the evidence its fidelity is certified from.
//
// `key` is the registry key and is deliberately NOT the association's `@id`. Two
// associations legitimately share an `@id` while being different lenses over it —
// the motivating satellite declares two vendor downscales of the same
// `audiostud/timeline-otio` canonical — so keying on `id` would have one silently
// evict the other. The key is `vendor/lens-name`.
//
// **There is no `fidelity()` accessor here, and that omission is the design.** The
// association's own `fidelity` field is documented at its declaration site as a
// CLAIM, proved or downgraded and never trusted; re-exporting it from the registry
// under a bare name would turn the estate's one honest claim into a
// registry-blessed fact, and a consumer deciding a write verb from it (see the
// composition engine's `Route::resourceRenderings()`, which decides exactly that)
// would be deciding it from a self-report. The only fidelity this type will answer
// is {@see certifiedFidelity()}, which runs the laws.
class LensRegistration
{
    private ?LensAssociation $resolved = null;

    /**
     * @param  string  $key  registry key, `vendor/lens-name` — not the association's `@id`
     * @param  LensTier  $tier  whose truth this lens is (visibility, never endorsement)
     * @param  LensAssociation|Closure(): LensAssociation  $association  the declaration; a closure stays lazy
     * @param  LensEvidence  $evidence  samples the laws are exercised against; empty ⇒ certifies nothing
     * @param  string|null  $owner  the declaring package/app in `vendor/name` form; null ⇒ app-local
     * @param  string  $of  what the lens is a projection OF, in plain words (for the index and CLI)
     */
    public function __construct(
        public string $key,
        public LensTier $tier,
        private LensAssociation|Closure $association,
        public LensEvidence $evidence = new LensEvidence,
        public ?string $owner = null,
        public string $of = '',
    ) {}

    // The declaration. Resolved once — a lazily registered association is built on
    // first read and reused, so repeated enumeration does not rebuild lens objects.
    public function association(): LensAssociation
    {
        return $this->resolved ??= $this->association instanceof Closure
            ? ($this->association)()
            : $this->association;
    }

    /**
     * The certified fidelity — never the claimed one. Two gates, mirroring
     * `Renderings\RenderingCertifier` in the composition engine so the two cannot
     * drift into different answers for the same association:
     *
     *  1. the evidence is non-empty — the laws over zero samples are vacuously true,
     *     so an unexercised claim is refused as `Lossy` rather than passed;
     *  2. the association survives GetPut/PutGet against every submitted sample —
     *     `ReversibleResolver::certify()` downgrades it to `Lossy` otherwise.
     *
     * Downgrade-only, with `Lossy` as the floor: a registration cannot talk its way
     * up. An honest `Lossy` declaration needs no evidence and gets the same answer
     * either way, which is the point — honesty is free, dishonesty buys nothing.
     */
    public function certifiedFidelity(ReversibleResolver $resolver): Fidelity
    {
        if ($this->evidence->empty()) {
            return Fidelity::Lossy;
        }

        return $resolver->certify(
            $this->association(),
            $this->evidence->canonicalSamples(),
            $this->evidence->renderingSamples(),
        )->fidelity;
    }

    /**
     * The flat row a manifest/CLI renders. `fidelity` here is the CERTIFIED value —
     * the key is unqualified precisely because there is no competing claimed one on
     * this surface — and `certified` records whether any evidence backed it, so
     * "proved lossy" and "never tested" stay distinguishable to a reader.
     *
     * @return array<string, mixed>
     */
    public function describe(ReversibleResolver $resolver): array
    {
        $association = $this->association();

        return [
            'key' => $this->key,
            'tier' => $this->tier->value,
            'owner' => $this->owner,
            'of' => $this->of,
            'id' => $association->id,
            'locator' => $association->locator,
            'direction' => $association->direction->value,
            'lens' => $association->lens::class,
            'fidelity' => $this->certifiedFidelity($resolver)->value,
            'certified' => ! $this->evidence->empty(),
            'complement' => $association->hasComplement(),
        ];
    }
}
