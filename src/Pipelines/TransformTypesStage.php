<?php

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
 *   - `types`  (string[], required) exported type names to extract. A bare short name
 *              (`ApiTokenData`) is enough while it is unique in the source; where two
 *              namespaces declare the same short name, give the qualified name
 *              (`Splicewire.Tower.Data.CalendarEventData`) — a bare name that matches more
 *              than once takes the FIRST declaration and is surfaced as an AMBIGUOUS note.
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
        $declarations = $this->index($haystack);
        $extracted = [];
        $names = [];

        foreach ($types as $type) {
            $candidates = $this->candidatesFor($declarations, $type);

            if ($candidates === []) {
                $context->note("TransformTypesStage: MISSING {$type} (not in {$source})");

                continue;
            }

            if (count($candidates) > 1) {
                // Two namespaces declare this short name, so the slice is specified by an
                // ambiguous key and first-match silently decides which contract ships. Which
                // one is right is a fact about the SOURCE artifact, not something the pipeline
                // author could have known host-blind, so this is advisory — but it must be
                // said, because the failure mode is a coherent bundle typed off the wrong class.
                $context->note(
                    "TransformTypesStage: AMBIGUOUS {$type} — ".count($candidates).' declarations ['
                    .implode(', ', array_column($candidates, 'qualified'))
                    .']; took ['.$candidates[0]['qualified'].']. Qualify the name in [types] to choose.'
                );
            }

            $slice = $this->extractAt($haystack, $candidates[0]['start']);

            if ($slice === null) {
                $context->note("TransformTypesStage: MISSING {$type} (unterminated declaration in {$source})");

                continue;
            }

            $extracted[] = $slice;
            $names[] = $candidates[0]['name'];
            $context->note("TransformTypesStage: extracted {$candidates[0]['qualified']}");
        }

        // A sliced type may reference a *sibling* type by the namespaced name it carries
        // in the app's single generated `.d.ts` (`App.Enums.TokenProvenance`,
        // `Splicewire.Beam.Commerce.Data.CreditEntryData`). The `_resources` slice is a flat,
        // self-contained module with no such global namespace, so those references must be
        // rewritten to the bare local names the slice actually emits — otherwise the
        // projection is a false green (skipLibCheck hides the dangling ref in the bundle, but
        // a consumer resolves the property to a missing namespace). Rewrite refs to co-sliced
        // types down to their bare name; flag any that remain (referenced but not sliced) so
        // the pipeline author adds them to `types`.
        //
        // Both halves are namespace-AGNOSTIC on purpose. They matched a literal `App` root
        // until 2026-09-03, which is the root the estate has migrated away from: every DTO
        // now emits under `Splicewire.*`, so the rewriter rewrote nothing and the detector
        // reported nothing while 23 dangling refs shipped across 7 of the 9 bundle files.
        // Reach before precision (ecosystem AGENTS.md) — an audit that parses for a construct
        // the estate has left behind reads as thorough exactly where it is blind.
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
     * Rewrite `<Root>.<Ns...>.<Name>` references to the bare `<Name>` for every `<Name>` that
     * was itself sliced into this bundle, so the flat module resolves internally. The root is
     * any capitalised namespace segment — `App`, `Splicewire`, `Schemastud`, whatever the
     * transformer emitted — never a hard-coded one.
     *
     * @param  array<int, string>  $names  bare names extracted into this slice
     */
    private function rewriteSiblingRefs(string $body, array $names): string
    {
        foreach ($names as $name) {
            $body = preg_replace(
                '/\b[A-Z]\w*(?:\.[A-Za-z_]\w*)*\.'.preg_quote($name, '/').'\b/',
                $name,
                $body
            );
        }

        return $body;
    }

    /**
     * Namespace-qualified references still present after sibling rewriting — types the slice
     * references but does not carry. Returned distinct, for author-facing notes.
     *
     * @return array<int, string>
     */
    private function danglingRefs(string $body): array
    {
        if (! preg_match_all('/\b[A-Z]\w*(?:\.[A-Za-z_]\w*)+\b/', $body, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[0]));
    }

    /**
     * Index every `export type` declaration in the generated `.d.ts` against the namespace
     * stack it sits in, so a slice can be requested by short name OR by qualified name and
     * a short name that resolves twice can be REPORTED rather than silently first-matched.
     *
     * @return array<int, array{qualified: string, name: string, start: int}>
     */
    private function index(string $haystack): array
    {
        $entries = [];
        $stack = [];
        $depth = 0;
        $offset = 0;

        foreach (explode("\n", $haystack) as $line) {
            $start = $offset;
            $offset += strlen($line) + 1;

            if (preg_match('/^\s*(?:declare\s+)?(?:export\s+)?namespace\s+([A-Za-z_]\w*)\s*\{\s*$/', $line, $m)) {
                $stack[] = ['name' => $m[1], 'depth' => $depth];
                $depth++;

                continue;
            }

            if (preg_match('/^\s*(?:export\s+)?type\s+([A-Za-z_]\w*)\s*=/', $line, $m)) {
                $path = array_column($stack, 'name');
                $path[] = $m[1];
                $entries[] = [
                    'qualified' => implode('.', $path),
                    'name' => $m[1],
                    // Offset of the declaration keyword, not of the line — the emitted slice
                    // must not carry the source's indentation.
                    'start' => $start + (strlen($line) - strlen(ltrim($line))),
                ];
            }

            $depth += substr_count($line, '{') - substr_count($line, '}');

            while ($stack !== [] && $depth <= $stack[count($stack) - 1]['depth']) {
                array_pop($stack);
            }
        }

        return $entries;
    }

    /**
     * Declarations answering a requested `types` entry: an exact qualified match wins outright;
     * otherwise every declaration sharing the short name, in source order.
     *
     * @param  array<int, array{qualified: string, name: string, start: int}>  $declarations
     * @return array<int, array{qualified: string, name: string, start: int}>
     */
    private function candidatesFor(array $declarations, string $wanted): array
    {
        $exact = array_values(array_filter($declarations, fn ($d) => $d['qualified'] === $wanted));

        if ($exact !== []) {
            return $exact;
        }

        return array_values(array_filter($declarations, fn ($d) => $d['name'] === $wanted));
    }

    /**
     * Extract a single `export type X = { ... };` block (object or one-line union) starting at
     * a known offset from {@see index()}. Objects can nest braces, so walk the balanced block
     * rather than a lazy `.*?`.
     */
    private function extractAt(string $haystack, int $start): ?string
    {
        $equals = strpos($haystack, '=', $start);

        if ($equals === false) {
            return null;
        }

        $cursor = $equals + 1;
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
