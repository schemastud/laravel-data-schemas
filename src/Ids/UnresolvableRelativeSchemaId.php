<?php

namespace Schemastud\DataSchemas\Ids;

use RuntimeException;
use Schemastud\DataSchemas\Support\SchemaAuthority;

/**
 * Thrown when a RELATIVE schema ref is resolved at a host that declares no authority to resolve it
 * against — `data-schemas.base_uri` unset, or declared as something that names no origin.
 *
 * ## This is not a reversal of ticket 112, and the distinction is the whole point
 *
 * Ticket 112 found all three starters shipping `base_uri = '/schemas'`, which minted `$id`s like
 * `/schemas/content/article/1` — relative *by accident*, because the declared authority named no
 * origin, and therefore unresolvable with nothing anywhere to resolve them against.
 * {@see \Schemastud\DataSchemas\Generators\NonAbsoluteSchemaBaseUri} is that guard and it stands.
 *
 * A ref reaching {@see RelativeSchemaIdParser} is relative *deliberately*: it is an authoring
 * spelling that expects the host's declared absolute authority to complete it, and it is resolved to
 * an absolute `$id` before anything freezes it. The two shapes look identical as strings and differ
 * entirely in what stands behind them — which is exactly why the absent-authority case must throw
 * here rather than pass the relative form through. **A deliberately-relative id with no absolute
 * authority behind it IS ticket 112 again**, arrived at from the ref side instead of the config side,
 * and this exception is where that door closes.
 *
 * ## Why there is still no default
 *
 * The obvious "fix" is a fallback authority, and the estate has already paid for that one: the
 * deleted package default `https://schemas.splicewire.app` reached three vendors and named a domain
 * nobody had registered. A `??` argument is the same default in a shorter spelling. An `$id` is
 * write-once, so an authority nobody has decided must fail loudly rather than be guessed — see
 * {@see \Schemastud\DataSchemas\Generators\MissingSchemaBaseUri}, whose ruling this inherits and
 * whose interface it shares.
 *
 * `base_uri => false` does NOT arrive here: an opted-out host has *decided* it mints no versioned
 * identity, which is a different state from having not decided. See {@see RelativeSchemaIdParser}.
 */
class UnresolvableRelativeSchemaId extends RuntimeException implements UndeclaredSchemaAuthority
{
    public function __construct(public string $ref, public string|bool|null $baseUri)
    {
        parent::__construct(
            SchemaAuthority::isAbsolute($baseUri) === false && is_string($baseUri) && trim($baseUri) !== ''
                ? sprintf(
                    'The relative schema ref "%s" cannot be resolved: "data-schemas.base_uri" is "%s", '
                    .'which names no origin. A relative ref is completed by the host\'s declared '
                    .'authority, so that authority must be an absolute URI (e.g. '
                    .'"https://app.example.com/schemas"). Resolving against a non-origin would mint the '
                    .'unresolvable relative $id ticket 112 removed. An $id is write-once, so there is '
                    .'no repair after the fact.',
                    $ref,
                    $baseUri,
                )
                : sprintf(
                    'The relative schema ref "%s" cannot be resolved: "data-schemas.base_uri" is not '
                    .'configured. Declare the origin that serves this host\'s schemas (e.g. '
                    .'"https://app.example.com/schemas"), or set it to false to declare that this host '
                    .'mints no versioned identity at all. There is deliberately no default authority: '
                    .'a fleet-wide one can only ever be some one vendor\'s domain, stamped onto every '
                    .'other vendor\'s schemas.',
                    $ref,
                ),
        );
    }
}
