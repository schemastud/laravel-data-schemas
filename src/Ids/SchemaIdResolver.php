<?php

namespace Schemastud\DataSchemas\Ids;

use Schemastud\JsonNs\NamespaceUri;
use Throwable;

/**
 * The ONE config-aware step in schema identity: a raw ref plus the host's declared authority in,
 * a resolved namespace URI out (beam-facade ticket 140).
 *
 * ## Why the config-awareness lives exactly here
 *
 * Ticket 113 ruled that `SchemaId::name()` takes its base as an ARGUMENT and refused to let it read
 * config: *"this is a pure value object over a string, deliberately container-free, and every caller
 * already knows which authority it is reading an `$id` under."* That ruling is preserved, not
 * worked around — the value objects stay pure and this class is the tier that legitimately reads
 * `data-schemas.base_uri`, because this package owns the key. Pushing resolution down into the value
 * object is the violation ticket 139 was created by.
 *
 * The parsers below are handed the authority for the same reason one tier further out. There is
 * exactly one `config()` read in the whole chain and it is in {@see fromConfig()}.
 *
 * ## What a caller gets that hand-concatenation did not
 *
 * A tri-state that is answered rather than assumed. `rtrim($base, '/').'/'.$ref` silently mints
 * `/content-schema/x/1` when `base_uri` is unset and `false/content-schema/x/1` when it is `false`;
 * routing through here throws on the first and returns the bare ref on the second. See
 * {@see RelativeSchemaIdParser} for the reasoning on all three states.
 */
class SchemaIdResolver
{
    /**
     * @param  string|bool|null  $baseUri  `data-schemas.base_uri` verbatim — tri-state, never
     *                                     normalized on the way in. Normalizing it here would be a
     *                                     default in disguise.
     * @param  array<int, SchemaIdParser|class-string<SchemaIdParser>>  $parsers  the registered
     *                                                                            grammars, in registration order; first to `handles()` the
     *                                                                            ref wins.
     * @param  SchemaIdParser  $floor  the grammar consulted when nobody claims. Substitutable but
     *                                 NOT shadowable — see {@see SchemaIdParser}.
     */
    public function __construct(
        private string|bool|null $baseUri,
        private array $parsers = [],
        private SchemaIdParser $floor = new RelativeSchemaIdParser,
    ) {}

    /**
     * Build from the booted container's config, tolerating its absence — the generator is
     * constructed bare in unit tests, and this must not be the thing that stops it.
     *
     * A missing container is NOT an opted-out host: `base_uri` stays null, so a relative ref still
     * throws {@see UnresolvableRelativeSchemaId} rather than quietly resolving to itself. The
     * tolerance is about the container, never about the ruling.
     */
    public static function fromConfig(): self
    {
        if (! function_exists('config')) {
            return new self(null);
        }

        try {
            $baseUri = config('data-schemas.base_uri');
            $parsers = config('data-schemas.id_parsers');
        } catch (Throwable) {
            return new self(null);
        }

        return new self(
            is_string($baseUri) || is_bool($baseUri) ? $baseUri : null,
            is_array($parsers) ? array_values($parsers) : [],
        );
    }

    /** The declared authority this resolver is reading refs under, verbatim. */
    public function baseUri(): string|bool|null
    {
        return $this->baseUri;
    }

    /**
     * Resolve a raw ref against the declared authority.
     *
     * @throws UnresolvableRelativeSchemaId when the ref needs an authority this host has not declared
     */
    public function resolve(string $ref): NamespaceUri
    {
        return $this->parserFor($ref)->parse($ref, $this->baseUri);
    }

    /** {@see resolve()}, as the `$id` string a document or a registry key actually carries. */
    public function resolveId(string $ref): string
    {
        return (string) $this->resolve($ref);
    }

    /**
     * The grammar that owns this ref: the first registrant to claim it, else the floor.
     *
     * A registrant that fails to instantiate is SKIPPED rather than fatal — a broken entry in
     * somebody else's config list must not take down every schema id at the host, and the floor is
     * always there to answer. The default absolute/relative grammar is the honest fallback for a ref
     * whose owner could not be built.
     */
    public function parserFor(string $ref): SchemaIdParser
    {
        foreach ($this->parsers as $parser) {
            $parser = $this->instantiate($parser);

            if ($parser !== null && $parser->handles($ref)) {
                return $parser;
            }
        }

        return $this->floor;
    }

    private function instantiate(SchemaIdParser|string $parser): ?SchemaIdParser
    {
        if ($parser instanceof SchemaIdParser) {
            return $parser;
        }

        if (function_exists('app')) {
            try {
                $resolved = app($parser);

                if ($resolved instanceof SchemaIdParser) {
                    return $resolved;
                }
            } catch (Throwable) {
                // Fall through to a plain instantiation.
            }
        }

        if (! class_exists($parser)) {
            return null;
        }

        $resolved = new $parser;

        return $resolved instanceof SchemaIdParser ? $resolved : null;
    }
}
