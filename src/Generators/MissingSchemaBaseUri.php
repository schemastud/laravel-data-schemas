<?php

namespace Schemastud\DataSchemas\Generators;

use RuntimeException;

/**
 * Thrown when a class opting into versioned identity is generated at a host that
 * has not declared `data-schemas.base_uri`.
 *
 * There is deliberately NO package default. A schema `$id` names the origin that
 * SERVES the schema, so the authority is a property of the host — a fleet-wide
 * default can only ever be some one vendor's domain, silently stamped onto every
 * other vendor's schemas. (The historical default, `https://schemas.splicewire.app`,
 * reached three vendors and named a domain that was never registered.)
 *
 * An `$id` is write-once, so minting one on a guessed authority is unrecoverable —
 * hence a loud failure at generation time rather than a plausible fallback.
 *
 * Set `base_uri` to `false` to opt this host out of versioned identity entirely:
 * a `SchemaIdentity` class then keeps the short-name `$id` it would have had
 * without the interface, and no schema-serving route is mounted.
 */
class MissingSchemaBaseUri extends RuntimeException
{
    public function __construct(public string $class)
    {
        parent::__construct(sprintf(
            '%s opts into versioned identity, but "data-schemas.base_uri" is not configured. '
            .'Declare the origin that serves this host\'s schemas (e.g. "https://app.example.com/schemas"), '
            .'or set it to false to opt out of versioned $ids. An $id is write-once, so there is no default.',
            $class,
        ));
    }
}
