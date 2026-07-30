<?php

namespace Schemastud\DataSchemas\Overlay\Lens;

// The free case made concrete: a record with no declared lens resolves as a
// plain embed. `get` and `put` are the identity, so a round-trip is byte-
// identical — exactly today's `ContentSource.load`/`save` behaviour. It is
// trivially lossless (bijective) and is the default a resolver falls back to
// when a record's facade-ref is absent.
class IdentityLens implements DirectedLens
{
    public function get(mixed $canonical): mixed
    {
        return $canonical;
    }

    public function put(mixed $rendering, mixed $priorCanonical, mixed $complement = null): mixed
    {
        return $rendering;
    }
}
