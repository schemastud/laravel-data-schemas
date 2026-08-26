<?php

namespace Schemastud\DataSchemas\Generators;

use RuntimeException;
use Schemastud\DataSchemas\Ids\UndeclaredSchemaAuthority;

/**
 * Thrown when `data-schemas.base_uri` is declared but does not name an ORIGIN.
 *
 * `base_uri` is documented as a tri-state — unset, `false`, or a URI — and a relative path like
 * `/schemas` is a FOURTH thing: it clears the `is_string` guard, mints `/schemas/content/article/1`,
 * and freezes that string into an artifact. Beam-facade ticket 39 measured what that costs at the
 * door: the formatted validator accepts a relative `$id`, opis cannot parse it, and the surrounding
 * `catch { return false }` reports "does not conform" — a wrong answer on a correct payload, with no
 * trace of the real cause. Ticket 112 found all three starters shipping exactly that value, so every
 * site cloned from one would have minted it.
 *
 * An `$id` is write-once, so this cannot be a warning. It is the same guard as
 * {@see MissingSchemaBaseUri} with one more condition on it, and the same reasoning: an authority
 * nobody has decided must fail rather than fall back, and an authority that is not an authority is
 * a decision nobody has finished making.
 *
 * This does NOT narrow ticket 64's tolerance. The package still has no opinion about WHICH origin a
 * host claims — a domain it has never heard of is fine. It only insists the value IS one: a scheme
 * and a host. `false` remains the way to opt out of versioned identity entirely.
 *
 * Carries {@see UndeclaredSchemaAuthority} so this ruling and its ref-side twin
 * ({@see \Schemastud\DataSchemas\Ids\UnresolvableRelativeSchemaId}, beam-facade ticket 140) are
 * catchable as one rule rather than two. Ticket 140 does NOT reverse this guard — a *deliberately*
 * relative ref carries a declared absolute authority to resolve against, and one that does not
 * lands back here by a different route.
 */
class NonAbsoluteSchemaBaseUri extends RuntimeException implements UndeclaredSchemaAuthority
{
    public function __construct(public string $class, public string $baseUri)
    {
        parent::__construct(sprintf(
            '%s opts into versioned identity, but "data-schemas.base_uri" is "%s", which names no origin. '
            .'A schema $id must be an absolute URI (e.g. "https://app.example.com/schemas") because it IS '
            .'the address the document is served at, and a relative $id cannot be resolved by a JSON Schema '
            .'validator. Declare this host\'s origin, or set base_uri to false to opt out of versioned $ids. '
            .'An $id is write-once, so there is no repair after the fact.',
            $class,
            $baseUri,
        ));
    }
}
