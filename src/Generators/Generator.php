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
     * The configured JSON Schema generator uses input wire names, omits computed
     * properties and permits omission of declared defaults. Use this for editable
     * forms; nullable alone does not make a property omittable.
     */
    public function forRequest(): static;

    /**
     * Return a variant configured to emit a response-flavoured schema.
     *
     * The configured JSON Schema generator uses output wire names and includes
     * computed properties. Defaulted properties remain required unless their type
     * is Optional or Lazy. Hidden properties stay absent in both projections.
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
