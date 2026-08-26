<?php

namespace Schemastud\DataSchemas\Tests\Fixtures\Discovery;

/**
 * A plain class under a scanned path. Popcorn enumerates it; `DataObjectCollector` drops it, which
 * is the split the fix depends on — discovery finds everything, the collector decides.
 */
class NotAData
{
    public string $title = '';
}
