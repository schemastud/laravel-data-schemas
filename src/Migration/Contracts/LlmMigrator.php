<?php

namespace Schemastud\DataSchemas\Migration\Contracts;

use Schemastud\DataSchemas\Migration\Rungs\LlmTryRung;
use Rushing\Popcorn\Contracts\Invocable;

/**
 * The LLM-try SEAM — a contract ONLY, no implementation here.
 *
 * When a host opts a field into LLM migration (#[MigrateWith('llm')] -> `x-migrate: llm`),
 * a model-backed migrator may attempt the shape change a deterministic rung
 * could not. data-schemas defines ONLY this seam and how it plugs into the ladder
 * (see {@see LlmTryRung}); it must
 * NEVER depend on Prism or any LLM SDK. The real implementation is app-side
 * (slice 17), bound into this contract there.
 *
 * An {@see LlmMigrator} IS a popcorn {@see Invocable} (payload-in / migrated-out),
 * so the host's model call rides the exact same transport-agnostic contract as
 * every other transform — local in-process, an MCP tool, or a webhook — and is
 * subject to the same acceptance gate. The model is just one more binding behind
 * the popcorn boundary; data-schemas sees an `array -> array` capability and
 * nothing about tokens, prompts, or providers.
 */
interface LlmMigrator extends Invocable
{
    // Inherits invoke(array $input): array, name(): string, binding(): Binding.
    // No LLM-specific surface leaks into data-schemas — the prompt/model/provider
    // live entirely on the host side of this seam.
}
