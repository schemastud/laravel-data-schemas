<?php

namespace Schemastud\DataSchemas\Contracts;

/**
 * A class that can hand over its own JSON Schema.
 *
 * The type-hint half of the schema-projection seam. It is an INTERFACE, not just a base class,
 * because a package that cannot take this one as a dependency — or a class whose `extends` slot is
 * already spoken for — must still be able to say "I provide a schema" and be believed. Six abstract
 * Data bases in this family are in exactly that position.
 *
 * STATIC, deliberately. A schema is a fact about the CLASS; there is no per-instance variation, and
 * an instance method would force a caller to construct an object in order to ask a class-level
 * question. See {@see \Schemastud\DataSchemas\Concerns\DerivesJsonSchema} for the default answer.
 *
 * Named `jsonSchema()`, not `schema()`: a census of this family found 44 methods named some variant
 * of "schema" across 12 packages, and `schema()` is a homonym — `OtioData::schema()` returns a URI
 * string, `Role::schema()` used to return an enum vocabulary. `jsonSchema()` says what comes back,
 * and matches the two existing precedents (`WorkflowBlueprint`, `SlotSchema`).
 */
interface ProvidesJsonSchema
{
    /** @return array<string, mixed> the JSON Schema document for this class */
    public static function jsonSchema(): array;
}
