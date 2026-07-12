<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

enum StatusEnum: string
{
    case Draft = 'draft';
    case Published = 'published';
}
