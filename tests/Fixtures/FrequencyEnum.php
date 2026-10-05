<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Schemastud\DataSchemas\Contracts\ProvidesEnumLabel;

/** An enum that declares human labels (ProvidesEnumLabel). */
enum FrequencyEnum: string implements ProvidesEnumLabel
{
    case Daily = 'daily';
    case Weekly = 'weekly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Every day',
            self::Weekly => 'Every week',
        };
    }
}
