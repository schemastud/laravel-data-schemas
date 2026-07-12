<?php

namespace Schemastud\DataSchemas\Attributes;

use Attribute;

/**
 * Declares that a property used to carry a DIFFERENT key in an earlier version
 * of the schema — the rename/move a structural {@see SchemaDiff} cannot infer
 * (a diff sees a dropped `oldKey` and an added `newKey`, but not that they are
 * the same field).
 *
 * Projected by {@see MigrationAttributesStrategy} onto the property schema as the
 * `x-migrate-from` keyword, which the declared-mappings migration rung consumes
 * to copy the old key's value into the new key.
 *
 * Migration metadata only rides VERSIONED schemas (classes implementing
 * SchemaIdentity); the strategy is a no-op otherwise, so non-migration schema
 * output is unchanged.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class WasNamed
{
    public function __construct(public string $from) {}
}
