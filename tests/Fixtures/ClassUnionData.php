<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Lazy;
use Spatie\LaravelData\Optional;

class UnionLeftData extends Data
{
    public function __construct(public string $id) {}
}

class UnionRightData extends Data
{
    public function __construct(public string $name) {}
}

class ClassUnionData extends Data
{
    public function __construct(
        public UnionLeftData|UnionRightData $choice,
        public UnionLeftData|UnionRightData|Optional $optional,
        public UnionLeftData|UnionRightData|null $nullable,
        public UnionLeftData|UnionRightData|Lazy $lazy,
        public UnionLeftData|string $scalar,
        public int|string|null $builtin,
        public UnionLeftData|Lazy|null $single,
    ) {}
}
