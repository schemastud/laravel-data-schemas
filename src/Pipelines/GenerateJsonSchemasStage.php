<?php

namespace Schemastud\DataSchemas\Pipelines;

use Closure;
use ReflectionClass;
use Rushing\PipelineRegistry\PipelineContext;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;

/**
 * Emits a JSON Schema per named Data class into a scope's `_resources` bundle, by
 * wrapping the package's existing {@see JsonSchemaGenerator}. A composable *stage*,
 * not an orchestration command — the pipeline registry owns the orchestration.
 *
 * The tokens surface is type-only (decision 01), so this stage is authored and
 * tested but rides idle in that pipeline; it lights up the moment a schema-driven
 * surface (a form) is rehomed, on the same rails.
 *
 * Options:
 *   - `data` (class-string[], required) Data classes to generate schemas for.
 *   - `emit` (string) context-relative output directory (default `schemas`).
 *   - `mode` (string) collapsed|request|response|llm_strict (default collapsed).
 */
class GenerateJsonSchemasStage
{
    /** @param  array{data?: array<int, class-string>, emit?: string, mode?: string}  $options */
    public function __construct(protected array $options = []) {}

    public function handle(PipelineContext $context, Closure $next): PipelineContext
    {
        $classes = $this->options['data'] ?? [];
        $dir = trim($this->options['emit'] ?? 'schemas', '/');
        $mode = $this->options['mode'] ?? 'collapsed';

        $generator = (new JsonSchemaGenerator)->schemaMode($mode);

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);

            if (! $generator->canGenerate($reflection)) {
                $context->note("GenerateJsonSchemasStage: SKIPPED {$class} (not generatable)");

                continue;
            }

            $schema = $generator->generate($reflection);
            $file = $dir.'/'.class_basename($class).'.json';
            $context->put($file, json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $context->note("GenerateJsonSchemasStage: generated {$file}");
        }

        return $next($context);
    }
}
