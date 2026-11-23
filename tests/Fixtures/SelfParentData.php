<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Spatie\LaravelData\Data;

class SelfParentBaseData extends Data
{
    public function __construct(public string $label = '') {}
}

/** A tree node: `self` names this class and `parent` its parent, both resolved against the DECLARING class. */
class SelfParentNodeData extends SelfParentBaseData
{
    public function __construct(
        public string $name,
        public ?self $child = null,
        public ?parent $base = null,
        public self|string|null $either = null,
    ) {
        parent::__construct();
    }
}
