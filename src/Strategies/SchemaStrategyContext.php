<?php

namespace Schemastud\DataSchemas\Strategies;

/**
 * The ambient information a property strategy may consult while contributing
 * keywords to a property schema: the generator's config (so a strategy can read
 * its own config slot, e.g. `validation_mapping`) and the current schema mode
 * (collapsed|request|response|llm_strict).
 */
class SchemaStrategyContext
{
    public function __construct(
        public array $config,
        public string $mode,
    ) {}
}
