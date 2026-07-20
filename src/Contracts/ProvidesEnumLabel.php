<?php

namespace Schemastud\DataSchemas\Contracts;

/**
 * A backed enum whose cases carry a human-readable label. The schema generator
 * reads this per-enum contract to emit `enumNames` alongside `enum` in the enum's
 * `$def`, so a rendered form shows "Daily" instead of the backing value `DAILY`.
 *
 * Opt-in and declaration-side per the vendor seam (ADR-0092): the projection logic
 * lives here in the open foundation; each enum that wants labels implements the
 * interface. An enum that does not implement it emits only `enum` (unchanged).
 */
interface ProvidesEnumLabel
{
    /** The human-readable label for this case, emitted as the matching `enumNames` entry. */
    public function label(): string;
}
