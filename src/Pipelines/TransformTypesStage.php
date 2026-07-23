<?php

declare(strict_types=1);

namespace Schemastud\DataSchemas\Pipelines;

use Closure;
use RuntimeException;
use Rushing\PipelineRegistry\PipelineContext;

/**
 * Projects a *slice* of named TypeScript types out of the app's single generated
 * `.d.ts` into a scope's `_resources` bundle.
 *
 * Why a slice and not a re-run of the spatie transformer: decision 01 fixed
 * generation as *app-side and singular* — the app runs `typescript:transform`
 * once, producing one `generated.d.ts`; the per-scope `_resources` projection is
 * a **distinct projection** off that one artifact (the `sync-typed-payloads`
 * precedent), not a second, drift-prone transform pass. Each scope carries only
 * the DTOs its components need, which keeps the vendor tier line intact by
 * construction (a foundation type slices into `schemastud/_resources`, an
 * app-shaped type into `splicewire/_resources`).
 *
 * Options:
 *   - `source` (string, required) absolute path to the generated `.d.ts`.
 *   - `types`  (string[], required) exported type names to extract.
 *   - `emit`   (string) context-relative output path (default `types/{scope}.d.ts`).
 *   - `scope`  (string) scope name, used in the emit default and banner.
 */
class TransformTypesStage
{
    /** @param  array{source?: string, types?: array<int, string>, emit?: string, scope?: string}  $options */
    public function __construct(protected array $options = []) {}

    public function handle(PipelineContext $context, Closure $next): PipelineContext
    {
        $source = $this->options['source'] ?? null;
        $types = $this->options['types'] ?? [];
        $scope = $this->options['scope'] ?? 'app';
        $emit = $this->options['emit'] ?? "types/{$scope}.d.ts";

        if ($source === null || ! is_file($source)) {
            throw new RuntimeException(
                'TransformTypesStage: source .d.ts not found'.($source ? " at [{$source}]" : ' (no [source] option)')
                .'. Run the app-side `typescript:transform` before this pipeline.'
            );
        }

        $haystack = file_get_contents($source);
        $extracted = [];
        $names = [];

        foreach ($types as $type) {
            $slice = $this->extractType($haystack, $type);

            if ($slice === null) {
                $context->note("TransformTypesStage: MISSING {$type} (not in {$source})");

                continue;
            }

            $extracted[] = $slice;
            $names[] = $type;
            $context->note("TransformTypesStage: extracted {$type}");
        }

        // A sliced type may reference a *sibling* type by the namespaced name it carries
        // in the app's single generated `.d.ts` (`App.Enums.TokenProvenance`,
        // `App.Data.FooData`). The `_resources` slice is a flat, self-contained module with
        // no `App` global namespace, so those references must be rewritten to the bare
        // local names the slice actually emits — otherwise the projection is a false green
        // (skipLibCheck hides the dangling ref in the bundle, but a consumer resolves the
        // property to a missing namespace). Rewrite refs to co-sliced types down to their
        // bare name; flag any that remain (referenced but not sliced) so the pipeline author
        // adds them to `types`.
        $body = implode("\n\n", $extracted);
        $body = $this->rewriteSiblingRefs($body, $names);

        foreach ($this->danglingRefs($body) as $ref) {
            $context->note("TransformTypesStage: DANGLING ref [{$ref}] — add its type to the [types] slice");
        }

        $banner = "// GENERATED — {$scope}/_resources — do not edit by hand.\n"
            ."// Projected from app/Data/* via the resources:{$scope} pipeline.\n\n";

        $context->put($emit, $banner.$body."\n");

        return $next($context);
    }

    /**
     * Rewrite `App.<Ns...>.<Name>` references to the bare `<Name>` for every `<Name>` that
     * was itself sliced into this bundle, so the flat module resolves internally.
     *
     * @param  array<int, string>  $names  bare names extracted into this slice
     */
    private function rewriteSiblingRefs(string $body, array $names): string
    {
        foreach ($names as $name) {
            $body = preg_replace(
                '/\bApp(?:\.[A-Za-z_]\w*)*\.'.preg_quote($name, '/').'\b/',
                $name,
                $body
            );
        }

        return $body;
    }

    /**
     * Namespaced `App.*` references still present after sibling rewriting — types the slice
     * references but does not carry. Returned distinct, for author-facing notes.
     *
     * @return array<int, string>
     */
    private function danglingRefs(string $body): array
    {
        if (! preg_match_all('/\bApp(?:\.[A-Za-z_]\w*)+\b/', $body, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[0]));
    }

    /**
     * Extract a single `export type X = { ... };` block (object or one-line union)
     * from the generated source. Objects can nest braces, so match the balanced
     * block rather than a lazy `.*?`.
     */
    private function extractType(string $haystack, string $type): ?string
    {
        $needle = 'export type '.$type.' =';
        $start = strpos($haystack, $needle);

        if ($start === false) {
            return null;
        }

        $cursor = $start + strlen($needle);
        $length = strlen($haystack);

        // Skip whitespace to the first meaningful char of the definition.
        while ($cursor < $length && ctype_space($haystack[$cursor])) {
            $cursor++;
        }

        if ($cursor < $length && $haystack[$cursor] === '{') {
            // Object type — walk to the matching closing brace, then the `;`.
            $depth = 0;
            for ($i = $cursor; $i < $length; $i++) {
                $char = $haystack[$i];
                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $i + 1;
                        // Include a trailing semicolon if present.
                        if ($end < $length && $haystack[$end] === ';') {
                            $end++;
                        }

                        return substr($haystack, $start, $end - $start);
                    }
                }
            }

            return null;
        }

        // Scalar / union type — runs to the terminating semicolon.
        $end = strpos($haystack, ';', $cursor);

        return $end === false ? null : substr($haystack, $start, $end + 1 - $start);
    }
}
