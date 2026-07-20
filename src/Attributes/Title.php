<?php

namespace Schemastud\DataSchemas\Attributes;

use Attribute;

/**
 * A human-readable property/class label, emitted as JSON Schema `title`. RJSF and
 * peer renderers use `title` as a field's visible label, falling back to the raw
 * property name when absent — so a `maxDistance` property reads "Similarity
 * threshold" instead of the identifier. A peer of {@see Description}.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS)]
class Title
{
    public function __construct(public string $value) {}
}
