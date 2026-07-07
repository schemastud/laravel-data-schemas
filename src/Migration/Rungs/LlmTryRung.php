<?php

namespace Rushing\LaravelDataSchemas\Migration\Rungs;

use Rushing\LaravelDataSchemas\Attributes\MigrateWith;
use Rushing\LaravelDataSchemas\Migration\Contracts\LlmMigrator;
use Rushing\LaravelDataSchemas\Migration\MigrationRequest;
use Rushing\LaravelDataSchemas\Migration\MigrationRung;
use Rushing\Popcorn\Contracts\Invocable;

/**
 * LLM-try rung — a host-bound, model-backed last resort BEFORE the quarantine
 * floor. It is NOT part of the default ladder; a host inserts it (with its own
 * {@see LlmMigrator}) only when it has opted in.
 *
 * The migrator is a popcorn {@see Invocable}, so this
 * rung dispatches it exactly like the custom-transform rung: payload in, candidate
 * out, then the SAME acceptance gate. data-schemas holds only the {@see LlmMigrator}
 * seam — the model, prompt, and provider live entirely on the host (slice 17), so
 * this package carries no Prism / LLM-SDK dependency.
 *
 * The rung only engages when the target schema actually opts a field into LLM
 * migration (`x-migrate: llm`), keeping the model out of migrations it was never
 * asked to attempt.
 */
class LlmTryRung extends MigrationRung
{
    protected ?LlmMigrator $migrator = null;

    public function name(): string
    {
        return 'llm-try';
    }

    /**
     * Bind the host's model-backed migrator. Without one, the rung abstains.
     */
    public function withMigrator(LlmMigrator $migrator): static
    {
        $this->migrator = $migrator;

        return $this;
    }

    protected function propose(MigrationRequest $request): ?array
    {
        if ($this->migrator === null) {
            return null;
        }

        // Only engage when a field has opted into LLM migration.
        if (! $this->hasLlmOptIn($request)) {
            return null;
        }

        // Dispatch the host migrator over the popcorn Invocable contract: it
        // receives the original payload and returns a candidate, which the base
        // rung's acceptance gate then validates against the target schema.
        return $this->migrator->invoke($request->payload);
    }

    protected function hasLlmOptIn(MigrationRequest $request): bool
    {
        foreach ($request->to['properties'] ?? [] as $prop) {
            if (($prop['x-migrate'] ?? null) === MigrateWith::LLM) {
                return true;
            }
        }

        return false;
    }
}
