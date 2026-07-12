<?php

namespace Schemastud\DataSchemas\Attributes;

use Attribute;

/**
 * Stamp an extension (`x-*`) keyword onto a property's emitted JSON Schema —
 * the generic escape valve for vocabulary the emitting class OWNS but this
 * package does not know (e.g. a host app's `x-widget` / `x-widget-options`
 * form hints). Repeatable; projected by KeywordAttributesStrategy.
 *
 * Ownership discipline is the declarer's: each host/package declares its own
 * keywords in its Keywords::owned() set, and the app-level ownership guard
 * unions those declarations — this attribute adds no vocabulary of its own.
 * Restricted to `x-`-prefixed names so structural JSON Schema keywords cannot
 * be clobbered from an annotation.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Keyword
{
    public function __construct(
        public string $name,
        public mixed $value = true,
    ) {}
}
