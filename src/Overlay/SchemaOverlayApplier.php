<?php

namespace Schemastud\DataSchemas\Overlay;

use Spatie\LaravelData\Data;

// The schema-side lifecycle envelope over the shared fold. Carries no op
// semantics of its own — it just folds the stack over a schema array and hands
// back an array.
//
// Lifecycle: **eager-only, forever.** A lazy schema engine could disagree with
// the server about the resolved schema, which would violate server authority;
// so a schema overlay is always applied server-side and baked. The author's
// downstream contract is to keep the artifact well-formed (the fold does not
// cascade — a dangling `required` is the author's to scrub with a companion
// unset; an opt-in meta-schema lint backstops this, a later slice).
class SchemaOverlayApplier
{
    protected array $schema;

    protected OverlayStack $stack;

    public function __construct(array|Data $schema, OverlayStack $stack)
    {
        $this->schema = $schema instanceof Data ? $schema->toArray() : $schema;
        $this->stack = $stack;
    }

    public function apply(): array
    {
        return $this->stack->apply($this->schema);
    }
}
