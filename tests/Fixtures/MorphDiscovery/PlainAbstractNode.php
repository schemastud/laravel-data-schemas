<?php

namespace Schemastud\DataSchemas\Tests\Fixtures\MorphDiscovery;

use Spatie\LaravelData\Data;

/** A plain abstract Data with NO #[MorphUnion] — discovery must STILL skip it. */
abstract class PlainAbstractNode extends Data
{
    public function __construct(public string $label) {}
}
