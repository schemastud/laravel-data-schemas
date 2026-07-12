<?php

namespace Schemastud\DataSchemas\Writers;

use Schemastud\DataSchemas\Support\SchemaCollection;

interface Writer
{
    public function write(SchemaCollection $collection): void;
}
