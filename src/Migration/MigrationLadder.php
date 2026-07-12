<?php

namespace Schemastud\DataSchemas\Migration;

use Schemastud\DataSchemas\Lifecycle\SchemaDiff;
use Schemastud\DataSchemas\Migration\Rungs\CustomTransformRung;
use Schemastud\DataSchemas\Migration\Rungs\DeclaredMappingRung;
use Schemastud\DataSchemas\Migration\Rungs\StructuralRung;
use Rushing\Popcorn\Strategy\StrategyLadder;

/**
 * The deterministic migration engine: a popcorn {@see StrategyLadder} of migration
 * rungs run STRONGEST-FIRST with the uniform acceptance gate, falling to a
 * QUARANTINE floor when every rung abstains.
 *
 * Ladder shape (strongest -> weakest):
 *   1. structural        — diff-driven add/drop/widen.
 *   2. declared-mapping  — x-migrate-from renames/moves.
 *   3. custom-transform  — a registered author Invocable (when a registry is set).
 *   (llm-try is NOT in the default ladder — a host inserts it explicitly.)
 *
 * Each rung self-validates against the TARGET `$id` schema (the acceptance gate)
 * and demotes when it cannot produce a conforming candidate. When the underlying
 * StrategyLadder returns null, the floor returns a {@see MigrationResult::quarantined()}
 * that preserves the ORIGINAL payload immutably — the source is never mutated or
 * destroyed.
 */
class MigrationLadder
{
    private StrategyLadder $ladder;

    /** @var array<int, MigrationRung> */
    private array $rungInstances;

    public function __construct(MigrationRung ...$rungs)
    {
        $this->rungInstances = $rungs;
        $this->ladder = new StrategyLadder(...$rungs);
    }

    /**
     * The DEFAULT ladder: structural -> declared -> (registered) custom-transform.
     * The custom rung is included but abstains unless a {@see TransformRegistry}
     * is supplied. The LLM-try rung is deliberately absent.
     */
    public static function default(?TransformRegistry $transforms = null): self
    {
        $gate = new AcceptanceGate;

        $custom = new CustomTransformRung($gate);
        if ($transforms !== null) {
            $custom->setRegistry($transforms);
        }

        return new self(
            new StructuralRung($gate),
            new DeclaredMappingRung($gate),
            $custom,
        );
    }

    /**
     * Append extra rungs (e.g. a host's LLM-try rung) to a fresh ladder built on
     * top of the default rungs.
     */
    public function withRungs(MigrationRung ...$extra): self
    {
        return new self(...[...$this->rungInstances, ...$extra]);
    }

    /**
     * Migrate a payload from its old schema to a target schema, computing the
     * structural diff and running it through the ladder.
     *
     * @param  array<string, mixed>  $payload  the source record, shaped to $from
     * @param  array<string, mixed>  $from  the OLD schema
     * @param  array<string, mixed>  $to  the TARGET schema (its `$id` is the acceptance target)
     */
    public function migrate(array $payload, array $from, array $to): MigrationResult
    {
        $request = new MigrationRequest(
            payload: $payload,
            from: $from,
            to: $to,
            diff: SchemaDiff::between($from, $to),
        );

        $result = $this->ladder->resolve($request->toInput());

        if ($result === null) {
            // Quarantine floor: nothing migrated, original preserved immutably.
            return MigrationResult::quarantined($payload);
        }

        /** @var array<string, mixed> $candidate */
        $candidate = $result->value;

        return MigrationResult::migrated($payload, $candidate, $result->strategy, $result->confidence);
    }

    /**
     * The rung names, strongest-first — for diagnostics / assertions.
     *
     * @return array<int, string>
     */
    public function rungs(): array
    {
        return $this->ladder->rungs();
    }
}
