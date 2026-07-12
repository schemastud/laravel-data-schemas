<?php

namespace Schemastud\DataSchemas\Attributes;

use Attribute;

/**
 * Declares the JSON Schema item type for a scalar array property (e.g. string[]),
 * so the generator can emit `items` — required by strict structured-output providers.
 *
 * `$type` is either a scalar JSON type token (`'string'`, `'integer'`, …) or a backed-enum
 * class name (e.g. `Status::class`), in which case the generator inlines the enum's values as
 * `items: {type: string, enum: [...]}` — a list of enum-valued scalars, without the `$ref`
 * indirection (or hydration semantics) of Spatie's #[DataCollectionOf]. For arrays of Data
 * objects use #[DataCollectionOf] instead.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ArrayItems
{
    public function __construct(public string $type) {}
}
