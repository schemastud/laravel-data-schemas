<?php

namespace Schemastud\DataSchemas\Tests\Fixtures\Discovery;

use Spatie\LaravelData\Data;

// A `::class` fetch standing before the declaration — the second thing the old regex read as a
// declaration. `const` rather than a docblock so the two failure modes are pinned separately.
const CLASS_FETCH_FIXTURE_BASE = Data::class;

class ClassFetchData extends Data
{
    public function __construct(
        public string $title = '',
    ) {}
}
