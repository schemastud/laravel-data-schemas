<?php

namespace Schemastud\DataSchemas\Ids;

use Schemastud\JsonNs\NamespaceUri;

/**
 * How a package's schema REFS parse — the seam beam-facade ticket 139 named and ticket 140 ships.
 *
 * ## The defect this exists to remove
 *
 * `Splicewire\Tower\Schema\ContentSchemaId` was a private static minter with `BASE` hardcoded to a
 * domain the host never declared, because there was nowhere for tower to say *how its own refs
 * spell*. So it invented an authority in order to have a grammar. This interface is that missing
 * place: a package declares its ref grammar and takes the authority from the host, instead of
 * minting privately with an authority it had to make up.
 *
 * ## Parsers claim, they do not transform
 *
 * {@see handles()} is asked first and the first claimer wins. A ref has exactly one
 * grammar; two parsers rewriting the same string in sequence would be a second grammar nobody
 * declared.
 *
 * {@see RelativeSchemaIdParser} is NOT in that ordered list — it is the floor
 * {@see SchemaIdResolver} falls to when nobody claims. That is deliberate: the estate's own
 * absolute/relative grammar must not be shadowable by a registrant, and putting it in the list
 * would make it either first (claiming everything, so no registrant ever fires) or last (which is
 * where appends land, so it would be shadowed by accident).
 *
 * ## The authority is an ARGUMENT
 *
 * A parser is handed the declared `base_uri` rather than reading config, which keeps beam-facade
 * ticket 113's ruling intact one tier up: the value objects stay container-free, and the ONE
 * config-aware seam is {@see SchemaIdResolver}, in the package that owns the key. A parser that
 * reaches for `config()` has re-created the defect above.
 */
interface SchemaIdParser
{
    /**
     * Whether this parser owns the given raw ref.
     *
     * Answered on the ref ALONE, never on the host's state: an authority that happens to be absent
     * is not a reason for a grammar to stop recognising its own spelling — it is a reason for
     * {@see parse()} to throw, which is a different and much more legible failure.
     */
    public function handles(string $ref): bool;

    /**
     * Resolve a raw ref into a namespace URI, given the host's declared authority.
     *
     * @param  string  $ref  the ref as written — an absolute `$id`, a relative id, or this parser's
     *                       own spelling.
     * @param  string|bool|null  $baseUri  `data-schemas.base_uri` verbatim, tri-state and all. A
     *                                     parser MUST answer for all three states; see
     *                                     {@see RelativeSchemaIdParser} for the estate's rulings on
     *                                     each.
     *
     * @throws UnresolvableRelativeSchemaId when the ref needs an authority this host has not declared.
     */
    public function parse(string $ref, string|bool|null $baseUri): NamespaceUri;
}
