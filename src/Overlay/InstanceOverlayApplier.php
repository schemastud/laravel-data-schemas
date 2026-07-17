<?php

namespace Schemastud\DataSchemas\Overlay;

use Spatie\LaravelData\Data;

// The instance-side lifecycle envelope over the shared fold. Same ops, same
// pure fold as the schema side — the only divergences are outside op semantics.
//
// Lifecycle: **eager now, lazy-eligible.** An instance is just data, so a future
// consumer could ship the stack and fold it client-side; nothing here forbids
// it. Author's downstream contract: none in scope — whether an overlaid instance
// still satisfies its (possibly overlaid) schema is deferred round-trip fog
// (SPEC §7). That is why re-hydrating a DTO is **caller-opt-in**: an `unset` can
// legitimately produce a shape the original Data class rejects, and `apply()`
// must still return it.
class InstanceOverlayApplier
{
    protected array $instance;

    protected OverlayStack $stack;

    public function __construct(array|Data $instance, OverlayStack $stack)
    {
        $this->instance = $instance instanceof Data ? $instance->toArray() : $instance;
        $this->stack = $stack;
    }

    // Fold and return the array-native result (the default).
    public function apply(): array
    {
        return $this->stack->apply($this->instance);
    }

    // Opt-in: fold, then re-hydrate the result into a Data class. Only call this
    // when the overlaid result is known to satisfy the DTO — an unset-shaped
    // result should stay an array (use apply()).
    public function hydrate(string $dataClass): Data
    {
        return $dataClass::from($this->apply());
    }
}
