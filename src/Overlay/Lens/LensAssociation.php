<?php

namespace Schemastud\DataSchemas\Overlay\Lens;

// A declared facade: the reversible, fidelity-typed generalisation of a
// forward-only overlay association. Where an overlay Action is a one-way fold
// (target + op + value), a LensAssociation binds a canonical `@id` and a
// rendering `locator` through a directed `get`/`put` lens, tagged with the
// `direction` that says which side is canonical and the `fidelity` that says
// whether the return trip is honest.
//
// The **committed core-5**: `id` (JSON-LD @id of the canonical), `locator`
// (JSON Pointer / JSONPath addressing the rendering), `direction`, `fidelity`,
// and the `get`/`put` lens. The sixth field, `complement`, is **reserved** —
// materialised only for the symmetric-with-complement case (a rendering holding
// private state absent from the canonical); it stays null for the near-bijective
// case and is not required to be populated here.
//
// This type is purely additive: it changes nothing about the existing
// override/merge/unset fold. A plain overlay with no LensAssociation declared
// resolves exactly as it does today.
class LensAssociation
{
    public function __construct(
        // JSON-LD `@id` of the canonical record (ADOPT — RFC-grade addressing).
        public string $id,
        // JSON Pointer / JSONPath addressing the rendering within the record.
        public string $locator,
        // Which side is canonical, so a resolver can tell get from put.
        public Direction $direction,
        // The honest round-trip claim; the resolver proves or downgrades it.
        public Fidelity $fidelity,
        // The get/put mechanism (ADR-0038 project()/hydrate(), made reversible).
        public DirectedLens $lens,
        // Reserved: the stored inverse the ops imply — rendering-private state
        // to reconstruct on the return trip. Null for the near-bijective case.
        public mixed $complement = null,
    ) {}

    // Convenience: the near-bijective declaration — a lossless-eligible lens in
    // the natural canonical→rendering orientation with no complement.
    public static function bijective(string $id, string $locator, DirectedLens $lens): self
    {
        return new self(
            id: $id,
            locator: $locator,
            direction: Direction::CanonicalToRendering,
            fidelity: Fidelity::LosslessEligible,
            lens: $lens,
        );
    }

    // Convenience: an honest lossy declaration — a lens whose return trip is
    // known to drop state, so the type-warning downgrades rather than dissolves.
    public static function lossy(string $id, string $locator, DirectedLens $lens): self
    {
        return new self(
            id: $id,
            locator: $locator,
            direction: Direction::CanonicalToRendering,
            fidelity: Fidelity::Lossy,
            lens: $lens,
        );
    }

    // Is a complement materialised for this association? Reserved-slot probe.
    public function hasComplement(): bool
    {
        return $this->complement !== null;
    }

    // A copy carrying a materialised complement (the symmetric-with-complement
    // case). Immutable — returns a new association.
    public function withComplement(mixed $complement): self
    {
        return new self(
            id: $this->id,
            locator: $this->locator,
            direction: $this->direction,
            fidelity: $this->fidelity,
            lens: $this->lens,
            complement: $complement,
        );
    }

    // The honest downgrade: a copy tagged `lossy`. The resolver returns this
    // when a lens declared lossless-eligible fails the well-behavedness laws —
    // relabelling the claim, never corrupting the content.
    public function downgraded(): self
    {
        return new self(
            id: $this->id,
            locator: $this->locator,
            direction: $this->direction,
            fidelity: Fidelity::Lossy,
            lens: $this->lens,
            complement: $this->complement,
        );
    }

    public function isLosslessEligible(): bool
    {
        return $this->fidelity === Fidelity::LosslessEligible;
    }
}
