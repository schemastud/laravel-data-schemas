<?php

namespace Schemastud\DataSchemas\Attributes;

use Attribute;

/**
 * Declares that an `array` property is a string-keyed MAP — `array<string, T>` — and names
 * the JSON Schema type of its VALUES, so the generator can emit `type: object` with
 * `additionalProperties`.
 *
 * PHP types a list and a map identically (`array`), so the generator has no signal to tell
 * them apart and defaulted every one to `type: array`. A map is a JSON **object** on the
 * wire: the reference said array, a generated client typed it `unknown[]`, and a strict
 * structured-output provider rejected the schema outright.
 *
 * This is the MAP peer of #[ArrayItems], deliberately a second attribute rather than a widened
 * one. They emit different JSON Schema keywords (`additionalProperties` vs `items`) under
 * different types (`object` vs `array`), so a single attribute would need a second parameter
 * to say which — the generator inferring a shape from a signal that does not carry it, which
 * is the defect this closes rather than a style to repeat.
 *
 * `$type` accepts the same vocabulary as #[ArrayItems] — a scalar JSON type token
 * (`'string'`, `'integer'`, …) or a backed-enum class, whose values are inlined — and
 * additionally a Data/object class, emitted as a `$ref` into `$defs`.
 *
 * It is OPTIONAL, and that is load-bearing rather than a convenience. Measured across the
 * estate, **49 of 55** map-shaped properties are `array<string, mixed>`: a bag whose value
 * type genuinely is not declarable. Bare `#[MapValues]` says the one thing that IS true —
 * this is an object, not an array — and emits no `additionalProperties`. Requiring a type
 * would have left the array/object lie standing on the 89% of maps that cannot answer it,
 * which is the same mistake as inferring a shape from a signal that does not carry it.
 *
 * JSON object keys are always strings, so there is no key-type slot: `array<string, T>` is
 * the only map shape JSON can carry, and `T` is the whole declaration.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class MapValues
{
    public function __construct(public ?string $type = null) {}
}
