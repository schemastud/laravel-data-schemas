<?php

namespace Schemastud\DataSchemas\Support;

use Illuminate\Support\Collection;

class SchemaCollection extends Collection
{
    public function addSchema(GeneratedSchema $schema): self
    {
        $this->items[] = $schema;

        return $this;
    }
}
