<?php

namespace Rushing\LaravelDataSchemas\Migration;

use Rushing\Popcorn\Strategy\Strategy;
use Rushing\Popcorn\Strategy\StrategyResult;

/**
 * Base class for a deterministic migration rung — one popcorn {@see Strategy} in
 * the {@see MigrationLadder}. It enforces the uniform discipline so every rung
 * behaves identically at the seams:
 *
 *  1. unpack the typed {@see MigrationRequest} from the popcorn array input;
 *  2. delegate to {@see propose()} for the rung-specific candidate;
 *  3. run the candidate through the {@see AcceptanceGate} against the TARGET
 *     schema — accepted only if it validates;
 *  4. on acceptance, return a {@see StrategyResult} carrying the migrated payload;
 *     otherwise return null (ABSTAIN) so the ladder demotes to the next rung.
 *
 * A rung that cannot even propose (no applicable change) also abstains by
 * returning null from {@see propose()}.
 */
abstract class MigrationRung implements Strategy
{
    public function __construct(
        protected AcceptanceGate $gate = new AcceptanceGate,
        protected float $confidence = 1.0,
    ) {}

    /**
     * Produce this rung's migration candidate, or null to abstain outright.
     *
     * @return array<string, mixed>|null
     */
    abstract protected function propose(MigrationRequest $request): ?array;

    public function attempt(array $input): ?StrategyResult
    {
        $request = MigrationRequest::fromInput($input);
        if ($request === null) {
            return null;
        }

        $candidate = $this->propose($request);
        if ($candidate === null) {
            return null;
        }

        // Uniform acceptance gate: a candidate must validate against the target
        // $id schema or the rung abstains and the ladder demotes.
        if (! $this->gate->accepts($candidate, $request->to)) {
            return null;
        }

        return new StrategyResult($candidate, $this->confidence, $this->name());
    }

    /**
     * The fields the target schema declares — the projection surface a rung
     * keeps its candidate within.
     *
     * @return array<int, string>
     */
    protected function targetFields(MigrationRequest $request): array
    {
        return array_keys($request->to['properties'] ?? []);
    }
}
