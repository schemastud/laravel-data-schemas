<?php

namespace Schemastud\DataSchemas\Tests\Fixtures\Vocabulary;

class SampleUnionSource
{
    public function keyword(): string|bool
    {
        return true;
    }
}
