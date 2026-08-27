<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Schemastud\DataSchemas\StudData;

/** The common case: no parent of its own, so it takes the short form. */
class StudBackedData extends StudData
{
    public function __construct(public string $title) {}
}
