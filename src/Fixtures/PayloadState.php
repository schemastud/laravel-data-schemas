<?php

namespace Schemastud\DataSchemas\Fixtures;

use RuntimeException;
use Spatie\LaravelData\Support\Creation\CreationContextFactory;
use Throwable;

/** A named state's attributes and their origin; no recording or HTTP knowledge belongs here. */
final readonly class PayloadState
{
    /** @param array<string, mixed> $attributes */
    public function __construct(public array $attributes, public string $source) {}

    /** @return array<string, mixed> */
    public function __invoke(array $built): array
    {
        return $this->attributes;
    }

    /** Hydrate the payload ALONE: no fixture defaults, states, overrides or hooks can repair drift. */
    public function verify(string $dataClass): void
    {
        try {
            CreationContextFactory::createFromConfig($dataClass)
                ->alwaysValidate()->withoutMagicalCreation()->from($this->attributes);
        } catch (Throwable $e) {
            throw new RuntimeException("Fixture payload [{$this->source}] no longer builds [{$dataClass}].", previous: $e);
        }
    }
}
