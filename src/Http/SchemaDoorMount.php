<?php

namespace Schemastud\DataSchemas\Http;

/**
 * Where the public schema door mounts, derived from `data-schemas.base_uri` (beam-facade ticket 82).
 *
 * The prefix is READ FROM the declared authority rather than hardcoded, because ticket 82 ruled the
 * declared string a PROMISE: a host that says it answers at `https://example.test/json/schema` must
 * answer there, not at whatever path this package happened to prefer.
 *
 * Only the PATH is used. The authority's host is deliberately discarded — the door is not domain-
 * constrained, because the `$id` it answers with is reconstructed from the incoming request. See
 * {@see SchemaDocumentController}.
 */
final class SchemaDoorMount
{
    /**
     * The route pattern for a declared authority, or null when nothing should mount.
     *
     * Mirrors `base_uri`'s tri-state exactly: unset (undecided) and `false` (opted out) both mount
     * nothing; a URI string mounts.
     */
    public static function patternFor(string|bool|null $baseUri): ?string
    {
        if (! is_string($baseUri) || $baseUri === '') {
            return null;
        }

        $path = trim((string) (parse_url($baseUri, PHP_URL_PATH) ?? ''), '/');

        return $path === '' ? '{path}' : $path.'/{path}';
    }
}
