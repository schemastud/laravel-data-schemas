<?php

namespace Rushing\LaravelDataSchemas\Tests\Fixtures\Vocabulary;

class SamplePolicySource
{
    /**
     * @param  list<string>  $key
     */
    public function __construct(
        public string $scope,
        public ?int $ttl = null,
        public array $key = [],
    ) {}
}
