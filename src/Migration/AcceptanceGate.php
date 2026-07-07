<?php

namespace Rushing\LaravelDataSchemas\Migration;

use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

/**
 * The uniform acceptance gate every migration rung passes its candidate through:
 * a rung's output is ACCEPTED only if it validates against the TARGET `$id`
 * schema via `opis/json-schema`. A candidate that does not conform means the rung
 * abstains, and the {@see MigrationLadder} demotes to the next, weaker rung.
 *
 * This is what makes the ladder self-validating: a strong rung that produces a
 * non-conforming shape steps aside rather than emitting a bad migration.
 */
class AcceptanceGate
{
    public function __construct(
        private Validator $validator = new Validator,
    ) {}

    /**
     * Whether $candidate conforms to the target schema.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $targetSchema
     */
    public function accepts(array $candidate, array $targetSchema): bool
    {
        // opis validates against a stdClass schema/data graph.
        $schema = Helper::toJSON($targetSchema);
        $data = Helper::toJSON($candidate);

        try {
            $result = $this->validator->validate($data, $schema);
        } catch (\Throwable) {
            // A schema opis cannot parse (e.g. an unresolved $ref) cannot vouch
            // for a candidate — the gate rejects rather than waving it through.
            return false;
        }

        return $result->isValid();
    }
}
