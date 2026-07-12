<?php

namespace Schemastud\DataSchemas\Generators;

use ReflectionClass;

interface Generator
{
    public function canGenerate(ReflectionClass $class): bool;

    public function generate(ReflectionClass $class): array;

    /**
     * Return a variant configured to emit a request-flavoured schema.
     *
     * The request/response divergence is a deferred seam (see ADR-0027); the
     * default implementation collapses to a single variant.
     */
    public function forRequest(): static;

    /**
     * Return a variant configured to emit a response-flavoured schema.
     */
    public function forResponse(): static;

    /**
     * Return a variant configured to emit a strict LLM structured-output schema
     * (every property required, optionals made nullable, additionalProperties:false).
     */
    public function forLlmStrict(): static;

    /**
     * Set the generation mode explicitly. Known modes: collapsed, request, response, llm_strict.
     */
    public function schemaMode(string $mode): static;
}
