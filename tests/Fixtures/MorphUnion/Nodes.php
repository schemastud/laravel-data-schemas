<?php

namespace Schemastud\DataSchemas\Tests;

use Schemastud\DataSchemas\Attributes\MorphUnion;
use Spatie\LaravelData\Attributes\PropertyForMorph;
use Spatie\LaravelData\Contracts\PropertyMorphableData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[MorphUnion(discriminator: 'kind', arms: ['text' => MorphText::class, 'block' => MorphBlock::class])]
abstract class MorphNode extends Data implements PropertyMorphableData
{
    public function __construct(#[PropertyForMorph] public string $kind) {}

    public static function morph(array $properties): ?string
    {
        return match ($properties['kind'] ?? null) {
            'text' => MorphText::class, 'block' => MorphBlock::class, default => null
        };
    }
}

#[TypeScript]
class MorphText extends MorphNode
{
    public function __construct(public string $text, string $kind = 'text')
    {
        parent::__construct($kind);
    }
}

#[TypeScript]
class MorphBlock extends MorphNode
{
    public function __construct(public ?MorphNode $child, string $kind = 'block')
    {
        parent::__construct($kind);
    }
}
