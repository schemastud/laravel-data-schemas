<?php

namespace Schemastud\DataSchemas\Overlay;

// The opt-in validation seam. The core fold is an **ungated pure fold** —
// production never validates. This interface is a dev/CI-only guardrail a host
// can run over an applier's output to catch authoring smells (e.g. an author
// who dropped a property with `unset` but forgot the companion `unset` for its
// `required` entry).
//
// It is deliberately **document-agnostic**: the base package ships only this
// interface. A schema-aware implementation (meta-schema lint + coherence checks)
// lives host/CI-side, so the fold stays dumb. It is a backstop, not a proof — a
// dangling `required` is still legal JSON Schema, so the lint catches smells,
// not all incoherence.
interface OverlayValidator
{
    /**
     * Lint a folded document. Returns a list of human-readable problem strings;
     * an empty list means the document passed. Implementations must not mutate
     * the document.
     *
     * @return string[]
     */
    public function validate(array $document): array;
}
