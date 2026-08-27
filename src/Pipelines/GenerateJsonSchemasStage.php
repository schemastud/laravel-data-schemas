<?php

namespace Schemastud\DataSchemas\Pipelines;

use Closure;
use ReflectionClass;
use Rushing\PipelineRegistry\PipelineContext;
use Schemastud\DataSchemas\Generators\ChainedGenerator;
use Schemastud\DataSchemas\Generators\Generator;
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
 * ## Why the generator comes from the container (beam-facade ticket 105, and after)
 *
 * {@see JsonSchemaGenerator} takes its config by CONSTRUCTOR ARGUMENT and never reaches the
 * container for it — deliberately, so it can be built bare in unit tests. This stage was the
 * estate's ONLY call site that built it bare in production code; every other path
 * ({@see \Schemastud\DataSchemas\Commands\GenerateJsonSchemaCommand}, the Scribe strategies,
 * `SchemaFreezeCommand`) passes real config. The consequence was silent: with no `base_uri` the
 * stage emitted artifacts carrying no `$id`, and since ticket 82 made `$id` the schema door's fetch
 * key, an artifact without one can never be served.
 *
 * Threading `config` fixed that, and left the residue: the constructor honours every key in the
 * array except `generators`, so this stage remained the one consumer blind to a host's generator
 * LIST. It now resolves {@see Generator} instead — see {@see generator()} for the seam, and for the
 * throw the `canGenerate()` guard below is now holding back.
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

        $generator = $this->generator()->schemaMode($mode);

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
     * The generator this stage emits with.
     *
     * Through the container by default, NOT `new JsonSchemaGenerator($config)`. Threading the
     * config array (ticket 105) fixed the missing `$schema`/`$id` half of the old defect but could
     * not fix the other half: the generator's constructor reads every key in that array EXCEPT
     * `generators`, so at a host configuring a list this stage kept generating with the package
     * default while every other consumer dispatched over the host's chain. Resolving
     * {@see Generator} is the one call that honours both the list AND a host that rebinds the
     * contract outright.
     *
     * An explicit `config` option still wins, and is built into its own chain rather than handed to
     * the container: the option exists precisely to override the host's config, and the binding
     * reads the host's config.
     *
     * The container path is not circular — the binding builds a {@see ChainedGenerator}, which
     * constructs generators directly and never resolves this stage.
     *
     * ⚠️ {@see ChainedGenerator::generate()} THROWS when no member accepts the class, where the bare
     * generator this replaced generated unconditionally. The `canGenerate()` guard in
     * {@see handle()} is what keeps that throw off the pipeline — it predates this change and was
     * incidental then; it is load-bearing now, and pinned by
     * `GenerateJsonSchemasStageTest::test_a_class_no_configured_generator_accepts_is_skipped_rather_than_thrown`.
     *
     * Falls back to a chain over `[]` only when no container is available — the same posture
     * {@see JsonSchemaGenerator::strategies()} already takes. A stage running inside a host has one.
     */
    protected function generator(): Generator
    {
        $explicit = $this->options['config'] ?? null;

        if (is_array($explicit)) {
            return ChainedGenerator::fromConfig($explicit);
        }

        if (! function_exists('app') || ! app()->bound('config')) {
            return ChainedGenerator::fromConfig([]);
        }

        return app(Generator::class);
    }
}
