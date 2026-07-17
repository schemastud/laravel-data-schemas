<?php

namespace Schemastud\DataSchemas\Overlay;

// Trivial default resolver: returns a fixed, ordered key list regardless of the
// ambient context. Ships in the base so the seam is usable out of the box (and
// as the container default); a real host swaps in a context-aware resolver.
class StaticOverlayResolver implements DataOverlayResolver
{
    /** @var string[] */
    protected array $keys;

    /**
     * @param  string[]  $keys
     */
    public function __construct(array $keys = [])
    {
        $this->keys = array_values($keys);
    }

    public function resolve(mixed $context = null): array
    {
        return $this->keys;
    }
}
