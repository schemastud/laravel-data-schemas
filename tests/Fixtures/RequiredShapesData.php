<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * The four shapes api-surface-coherence 31 measured, in one class.
 *
 * All four are PROMOTED, which is the point: `ReflectionProperty::hasDefaultValue()` returns
 * false for every one of them regardless of the constructor default, so raw reflection cannot
 * tell $a and $b apart at all.
 *
 * Defaulted parameters come last so PHP does not implicitly promote the undefaulted ones past
 * them — an ordering constraint of the language, not of the shapes being asserted.
 */
class RequiredShapesData extends Data
{
    public function __construct(
        public ?string $b,              // nullable, no default    → required both ways
        public string|Optional $d,      // Optional                → absent both ways
        public ?string $a = null,       // nullable, defaulted     → optional IN, present OUT
        public bool $c = true,          // non-nullable, defaulted → optional IN, present OUT
    ) {}
}
