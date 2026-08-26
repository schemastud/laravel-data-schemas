<?php

namespace Schemastud\DataSchemas\Http;

use Schemastud\DataSchemas\Support\SchemaAuthority;

/**
 * Where the public schema door mounts, derived from `data-schemas.base_uri` (beam-facade ticket 82).
 *
 * The prefix is READ FROM the declared authority rather than hardcoded, because ticket 82 ruled the
 * declared string a PROMISE: a host that says it answers at `https://example.test/json/schema` must
 * answer there, not at whatever path this package happened to prefer.
 *
 * For a PATH-SHAPED authority only the path is used, and the authority's host is deliberately
 * discarded — the door is not domain-constrained, because the `$id` it answers with is reconstructed
 * from the incoming request. See {@see SchemaDocumentController}.
 *
 * A PATH-LESS authority (`https://schemas.example.com`) is the one case where that cannot hold, and
 * beam-facade ticket 111 measured why. Its pattern is `{path}` — a ROOT catch-all — and Laravel's
 * `RouteCollection` keys by domain+method+URI, so a second bare `GET {path}` registered anywhere in
 * the host does not lose a race, it REPLACES this one outright and nothing reports it. Measured at
 * `~/Herd/splicewire` on 2026-08-24: `beam-ux`'s `GET {path}` (`beam.ux.site.show`) had silently
 * overwritten the door, `getByName('data-schemas.document')` returned `false`, and a request for the
 * host's own frozen `$id` matched the beam-ux entry controller.
 *
 * So a path-less authority mounts DOMAIN-CONSTRAINED, via {@see domainFor()}. Two reasons, and the
 * first is the load-bearing one:
 *
 *  1. For a path-less authority the host is the ONLY thing that distinguishes a door request from
 *     every other request to the site. An unconstrained root catch-all is not "the door with a wide
 *     pattern", it is the door claiming the whole site — never what a host declaring a dedicated
 *     `schemas.` subdomain meant.
 *  2. Laravel indexes domain routes in a SEPARATE bucket that `RouteCollection::get()` merges ahead
 *     of the undomained ones, so the constrained door neither replaces nor is replaced by a sibling
 *     root catch-all, and it is tried first on its own host.
 *
 * This is deliberately NOT a general re-domaining: a path-shaped authority still mounts
 * unconstrained, which is what keeps the tenant-authority extension path ticket 64 asked to hold
 * open exactly as open as it was.
 *
 * A NON-ABSOLUTE authority mounts nothing. `/schemas` passes the `is_string` guard but names no
 * origin, and the served registry is keyed by the absolute request URL, so `schemas/{path}` would
 * mount a door that can only ever 404 (beam-facade ticket 112). Refusing to mount it is the honest
 * report; {@see \Schemastud\DataSchemas\Generators\NonAbsoluteSchemaBaseUri} is the loud one, thrown
 * on the identity half where an unrecoverable write-once `$id` is actually at stake.
 */
class SchemaDoorMount
{
    /**
     * The route pattern for a declared authority, or null when nothing should mount.
     *
     * Mirrors `base_uri`'s tri-state exactly: unset (undecided) and `false` (opted out) both mount
     * nothing; an absolute URI string mounts. A string that is not an absolute URI mounts nothing —
     * see the class docblock.
     */
    public static function patternFor(string|bool|null $baseUri): ?string
    {
        if (! SchemaAuthority::isAbsolute($baseUri)) {
            return null;
        }

        $path = SchemaAuthority::pathOf($baseUri);

        return $path === '' ? '{path}' : $path.'/{path}';
    }

    /**
     * The domain the door should be constrained to, or null to mount unconstrained.
     *
     * Non-null for a path-less authority ONLY, because that is the only shape whose pattern is a
     * root catch-all. See the class docblock for why that case cannot be left unconstrained.
     */
    public static function domainFor(string|bool|null $baseUri): ?string
    {
        if (self::patternFor($baseUri) !== '{path}') {
            return null;
        }

        return SchemaAuthority::hostOf($baseUri);
    }
}
