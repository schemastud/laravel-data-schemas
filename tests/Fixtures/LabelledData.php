<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Spatie\LaravelData\Data;

/** No class-level #[Title]: under `identifier_titles => false` its root title is absent, not "LabelledData". */
class LabelledData extends Data
{
    public function __construct(
        public StatusEnum $status,
        public FrequencyEnum $frequency,
        public TitledData $titled,
        public ?UserData $owner = null,
    ) {}
}
