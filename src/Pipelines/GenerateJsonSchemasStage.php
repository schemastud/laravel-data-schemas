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
 *   - `config` (array) generator config; defaults to the host's `data-schemas` config.
 *
 * ## Why `config` is threaded (beam-facade ticket 105)
 *
 * {@see JsonSchemaGenerator} takes its config by CONSTRUCTOR ARGUMENT and never reaches the
 * container for it — deliberately, so it can be built bare in unit tests. This stage was the
 * estate's ONLY call site that built it bare in production code; every other path
 * ({@see \Schemastud\DataSchemas\Commands\GenerateJsonSchemaCommand}, the Scribe strategies,
 * `SchemaFreezeCommand`) passes real config. The consequence was silent: with no `base_uri` the
 * stage emitted artifacts carrying no `$id`, and since ticket 82 made `$id` the schema door's fetch
 * key, an artifact without one can never be served. The stage rides idle in the tokens pipeline, so
 * this was a defect waiting on a consumer rather than a live outage — which is the argument for
 * fixing it while nothing depends on its current output, not against.
 */
class GenerateJsonSchemasStage
{
    /** @param  array{data?: array<int, class-string>, emit?: string, mode?: string, config?: array<string, mixed>}  $options */
    public function __construct(protected array $options = []) {}

    public function handle(PipelineContext $context, Closure $next): PipelineContext
    {
        $classes = $this->options['data'] ?? [];
        $dir = trim($this->options['emit'] ?? 'schemas', '/');
        $mode = $this->options['mode'] ?? 'collapsed';

        $generator = (new JsonSchemaGenerator($this->generatorConfig()))->schemaMode($mode);

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

    /**
     * The generator config: an explicit `config` option wins, else the host's `data-schemas` config.
     *
     * Falls back to `[]` only when no container is available — the same posture
     * {@see JsonSchemaGenerator::strategies()} already takes, and the only shape under which a bare
     * generator is legitimate. A stage running inside a host always has one.
     *
     * @return array<string, mixed>
     */
    protected function generatorConfig(): array
    {
        $explicit = $this->options['config'] ?? null;

        if (is_array($explicit)) {
            return $explicit;
        }

        if (! function_exists('app') || ! app()->bound('config')) {
            return [];
        }

        return (array) config('data-schemas', []);
    }
}
