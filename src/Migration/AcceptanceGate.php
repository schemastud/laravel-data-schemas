<?php

namespace Schemastud\DataSchemas\Migration;

use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use Schemastud\DataSchemas\Support\OpisSchema;
use Schemastud\JsonNs\Vocab\VocabularyValidator;
use Schemastud\JsonNs\Vocabulary;

/**
 * The uniform acceptance gate every migration rung passes its candidate through:
 * a rung's output is ACCEPTED only if it validates against the TARGET `$id`
 * schema via `opis/json-schema`. A candidate that does not conform means the rung
 * abstains, and the {@see MigrationLadder} demotes to the next, weaker rung.
 *
 * This is what makes the ladder self-validating: a strong rung that produces a
 * non-conforming shape steps aside rather than emitting a bad migration.
 *
 * Namespace-aware (beam-namespace-wiring ticket 03): when the target schema declares
 * `@namespace`/`@namespaced` content, the candidate's namespaced subtrees are ADDITIONALLY
 * enforced against their namespaces' `$vocabulary` schemas via the json-ns
 * {@see VocabularyValidator} — the same enforcement the formatted intake door runs (ticket
 * 02), so a namespaced document can never pass one door and not the other. The gate stays a
 * pure boolean; formatted errors remain the door's job (the two-gate split is deliberate).
 * The enforcement engine is the injected instance, else the host container's binding
 * (JsonNsServiceProvider) — resolved lazily so the bare `new AcceptanceGate` construction
 * sites (the ladder, the rungs) enforce identically to container-made gates. A host with no
 * json-ns wiring keeps the plain structural gate.
 *
 * Parity extends to the schema's own identity (beam-facade ticket 51): both doors prepare the
 * document through {@see OpisSchema::withoutRelativeId()}, so a schema carrying a relative `$id`
 * cannot pass the formatted door and be reported non-conforming here.
 */
class AcceptanceGate
{
    public function __construct(
        private Validator $validator = new Validator,
        private ?VocabularyValidator $vocabularies = null,
    ) {}

    /**
     * Whether $candidate conforms to the target schema.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $targetSchema
     */
    public function accepts(array $candidate, array $targetSchema): bool
    {
        // opis validates against a stdClass schema/data graph. A relative `$id` is stripped first,
        // in the SAME preparation the formatted intake door runs — see {@see OpisSchema}, which is
        // what makes this class's parity claim true by construction rather than by intention.
        $schema = Helper::toJSON(OpisSchema::withoutRelativeId($targetSchema));
        $data = Helper::toJSON($candidate);

        try {
            $result = $this->validator->validate($data, $schema);
        } catch (\Throwable) {
            // A schema opis cannot parse (e.g. an unresolved $ref) cannot vouch
            // for a candidate — the gate rejects rather than waving it through.
            return false;
        }

        if (! $result->isValid()) {
            return false;
        }

        return $this->namespacesAccept($candidate, $targetSchema);
    }

    /**
     * Per-namespace `$vocabulary` conformance of the candidate's namespaced subtrees (ticket
     * 03). True when the target declares no namespace content (the additive guarantee), when
     * no enforcement engine is available, or when every namespaced subtree conforms. The
     * target's declarations govern the candidate: they are overlaid before scoping, exactly
     * as the intake door does.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $targetSchema
     */
    protected function namespacesAccept(array $candidate, array $targetSchema): bool
    {
        $declarations = Vocabulary::declarationsOf($targetSchema);

        if ($declarations === []) {
            return true;
        }

        $vocabularies = $this->vocabularies();

        if ($vocabularies === null) {
            return true;
        }

        try {
            return $vocabularies->passes($declarations + $candidate);
        } catch (\Throwable) {
            // Same posture as the structural pass: an unenforceable declaration (unbound
            // prefix, missing required artifact) cannot vouch for the candidate.
            return false;
        }
    }

    /**
     * The enforcement engine: injected, else the host container's registry-backed binding.
     */
    protected function vocabularies(): ?VocabularyValidator
    {
        if ($this->vocabularies !== null) {
            return $this->vocabularies;
        }

        if (function_exists('app') && app()->bound(VocabularyValidator::class)) {
            return $this->vocabularies = app(VocabularyValidator::class);
        }

        return null;
    }
}
