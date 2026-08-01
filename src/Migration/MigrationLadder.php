<?php

namespace Schemastud\DataSchemas\Migration;

use Rushing\Popcorn\Strategy\StrategyLadder;
use Schemastud\DataSchemas\Lifecycle\SchemaDiff;
use Schemastud\DataSchemas\Migration\Rungs\CustomTransformRung;
use Schemastud\DataSchemas\Migration\Rungs\DeclaredMappingRung;
use Schemastud\DataSchemas\Migration\Rungs\SourceProjectionRung;
use Schemastud\DataSchemas\Migration\Rungs\StructuralRung;
use Schemastud\DataSchemas\Migration\Source\ForeignSource;

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
     * The FOREIGN-source ladder: the entry used when the bottom rung is a foreign
     * shape rather than a prior schema version. The declarative
     * {@see SourceProjectionRung} (x-source path+cast) runs first, then the custom
     * transform escape hatch for anything path+cast cannot express. The structural
     * required-fields floor is folded INTO the projection rung (it fills every
     * non-projected target field), so no separate structural/declared rung is
     * needed — a version-diff has no meaning for a foreign entry.
     */
    public static function forForeignSource(?TransformRegistry $transforms = null): self
    {
        $gate = new AcceptanceGate;

        $custom = new CustomTransformRung($gate);
        if ($transforms !== null) {
            $custom->setRegistry($transforms);
        }

        return new self(
            new SourceProjectionRung($gate),
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
     * Enter the ladder from a FOREIGN source shape against a target schema — the
     * projection seam. The foreign payload is run through the same ladder machinery
     * with the {@see ForeignSource} sentinel as the `$from` descriptor, so:
     *
     *  - diffing marks every target field ADDED (the structural required-fields
     *    floor, filled inside the projection rung);
     *  - `x-source` annotations project nested foreign values in;
     *  - missing-required after projection fails the SAME {@see AcceptanceGate} and
     *    QUARANTINES (never a silent pass);
     *  - the custom-transform rung remains reachable for the bespoke tail.
     *
     * A target schema with no `$id` is UNSCHEMATIZED for the purposes of a persisted
     * projection: it cannot enter the ladder and this method throws, enforcing the
     * "unschematized source → ephemeral-only, never a persisted shadow" boundary.
     * (Callers wanting an ephemeral, unvalidated shape simply do not enter here.)
     *
     * NOTE: expressed entirely in schemastud's own terms — the from-descriptor is
     * {@see ForeignSource::descriptor()}, a schemastud-local sentinel. No
     * `Splicewire\*` type is referenced; the beam-side `ParticleSource`→entry
     * adapter maps its own source kind onto this call.
     *
     * @param  array<string, mixed>  $foreign  the foreign source payload (any shape)
     * @param  array<string, mixed>  $target  the TARGET schema (its `$id` is the acceptance target)
     */
    public function project(array $foreign, array $target): MigrationResult
    {
        $id = $target['$id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new \InvalidArgumentException(
                'Cannot project a foreign source onto an unschematized target (no $id): '
                .'unschematized output is ephemeral-only and may never enter the ladder.'
            );
        }

        return $this->migrate($foreign, ForeignSource::descriptor(), $target);
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
