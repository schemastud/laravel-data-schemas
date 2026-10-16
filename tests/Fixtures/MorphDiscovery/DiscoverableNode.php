<?php

namespace Schemastud\DataSchemas\Tests\Fixtures\MorphDiscovery;

use Schemastud\DataSchemas\Attributes\MorphUnion;
use Spatie\LaravelData\Attributes\PropertyForMorph;
use Spatie\LaravelData\Contracts\PropertyMorphableData;
use Spatie\LaravelData\Data;

/**
 * A MorphUnion base that must be reachable through NORMAL discovery (a directory scan), not only by
 * handing the class straight to the transformer. PSR-4: one class per file so autoload (and therefore
 * the reflection the discovery action does) resolves it.
 */
#[MorphUnion(discriminator: 'kind', arms: ['leaf' => DiscoverableLeaf::class, 'branch' => DiscoverableBranch::class])]
abstract class DiscoverableNode extends Data implements PropertyMorphableData
{
    public function __construct(#[PropertyForMorph] public string $kind) {}

    public static function morph(array $properties): ?string
    {
        return match ($properties['kind'] ?? null) {
            'leaf' => DiscoverableLeaf::class,
            'branch' => DiscoverableBranch::class,
            default => null,
        };
    }
}
