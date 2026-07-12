<?php

namespace Schemastud\DataSchemas\Migration;

use Rushing\Popcorn\Contracts\Invocable;

/**
 * A small registry of author-registered custom migration transforms, each a
 * popcorn {@see Invocable} (local PHP, MCP, or webhook — the binding is the
 * transform's own concern). The {@see Rungs\CustomTransformRung} looks one up by:
 *
 *  - a `from->to` `$id` PAIR (the most specific key — this transform migrates
 *    exactly version X to version Y); or
 *  - a NAME (the `x-migrate: Transform::class` pin carried on the target schema).
 *
 * data-schemas only knows the popcorn contract; it never knows what the transform
 * actually does or where it runs.
 */
class TransformRegistry
{
    /** @var array<string, Invocable> */
    private array $byPair = [];

    /** @var array<string, Invocable> */
    private array $byName = [];

    /**
     * Register a transform for a specific from->to `$id` migration.
     */
    public function registerPair(string $fromId, string $toId, Invocable $transform): static
    {
        $this->byPair[$this->pairKey($fromId, $toId)] = $transform;

        return $this;
    }

    /**
     * Register a transform under a name (matched against an `x-migrate` pin).
     */
    public function registerName(string $name, Invocable $transform): static
    {
        $this->byName[$name] = $transform;

        return $this;
    }

    public function forPair(string $fromId, string $toId): ?Invocable
    {
        return $this->byPair[$this->pairKey($fromId, $toId)] ?? null;
    }

    public function forName(string $name): ?Invocable
    {
        return $this->byName[$name] ?? null;
    }

    private function pairKey(string $fromId, string $toId): string
    {
        return $fromId.'->'.$toId;
    }
}
