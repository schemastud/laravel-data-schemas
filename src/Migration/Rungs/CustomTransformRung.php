<?php

namespace Schemastud\DataSchemas\Migration\Rungs;

use Schemastud\DataSchemas\Attributes\MigrateWith;
use Schemastud\DataSchemas\Keywords;
use Schemastud\DataSchemas\Migration\MigrationRequest;
use Schemastud\DataSchemas\Migration\MigrationRung;
use Schemastud\DataSchemas\Migration\TransformRegistry;
use Rushing\Popcorn\Contracts\Invocable;

/**
 * Rung 3 — runs an author-registered custom transform for the migration. The
 * target schema pins a transform via `x-migrate: Transform::class` (from
 * #[MigrateWith]); the matching popcorn {@see Invocable}
 * — local, MCP, or webhook — is looked up in the {@see TransformRegistry} keyed by
 * the `from->to` `$id` pair (falling back to the pin name) and invoked: payload in,
 * migrated record out.
 *
 * The invocable's output is array-in / array-out (the popcorn contract), then run
 * through the same acceptance gate as every other rung — a transform that emits a
 * non-conforming shape makes the rung abstain and the ladder demotes.
 */
class CustomTransformRung extends MigrationRung
{
    public function name(): string
    {
        return 'custom-transform';
    }

    public function setRegistry(TransformRegistry $registry): static
    {
        $this->registry = $registry;

        return $this;
    }

    protected ?TransformRegistry $registry = null;

    protected function propose(MigrationRequest $request): ?array
    {
        if ($this->registry === null) {
            return null;
        }

        $invocable = $this->resolveInvocable($request);
        if ($invocable === null) {
            return null;
        }

        $output = $invocable->invoke($request->payload);

        // The transform owns the full target shape; a non-conforming result is
        // rejected by the acceptance gate in the base rung.
        return $output;
    }

    /**
     * Resolve the registered transform invocable for this migration.
     *
     * Resolution order:
     *  1. the from->to `$id` pair (most specific);
     *  2. any `x-migrate` class-string pin on the target schema (not the `'llm'`
     *     opt-in, which belongs to the host-bound LLM rung).
     */
    protected function resolveInvocable(MigrationRequest $request): ?Invocable
    {
        $fromId = $request->fromId();
        $toId = $request->toId();

        if ($fromId !== null && $toId !== null) {
            $byPair = $this->registry->forPair($fromId, $toId);
            if ($byPair !== null) {
                return $byPair;
            }
        }

        foreach ($this->pins($request) as $pin) {
            if ($pin === MigrateWith::LLM) {
                continue; // host-bound LLM rung owns this, not the custom rung
            }
            $byName = $this->registry->forName($pin);
            if ($byName !== null) {
                return $byName;
            }
        }

        return null;
    }

    /**
     * The distinct `x-migrate` pins declared across the target schema's fields.
     *
     * @return array<int, string>
     */
    protected function pins(MigrationRequest $request): array
    {
        $pins = [];
        foreach ($request->to['properties'] ?? [] as $prop) {
            $pin = $prop[Keywords::Migrate] ?? null;
            if (is_string($pin) && $pin !== '') {
                $pins[$pin] = true;
            }
        }

        return array_keys($pins);
    }
}
