<?php

namespace Rushing\LaravelDataSchemas\Tests\Fixtures\Vocabulary;

class SampleUnionSource
{
    public function keyword(): string|bool
    {
        return true;
    }
}
