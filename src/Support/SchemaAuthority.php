<?php

namespace Schemastud\DataSchemas\Support;

/**
 * What `data-schemas.base_uri` is allowed to be, in one place.
 *
 * The key has two independent consumers — the identity half ({@see
 * \Schemastud\DataSchemas\Generators\JsonSchemaGenerator::versionedId()}, which freezes the value
 * into a write-once `$id`) and the serving half ({@see \Schemastud\DataSchemas\Http\SchemaDoorMount},
 * which turns it into a route). Beam-facade ticket 112 found them disagreeing about what the key
 * meant: the generator concatenated anything string-ish, the mount routed anything string-ish, and
 * `/schemas` sailed through both to mint an `$id` no validator can resolve and mount a door that
 * could only ever 404. This predicate is the shared answer, so neither half can drift from the other
 * again — it sits under `Support` rather than beside either consumer for exactly that reason.
 */
class SchemaAuthority
{
    /**
     * Whether a declared `base_uri` names an ORIGIN — a scheme AND a host.
     *
     * Deliberately structural, not a whitelist: ticket 64 keeps this package ignorant of WHICH
     * authority a host claims, and a domain it has never heard of is entirely legal. It only
     * insists the value is one. `false` and unset are not authorities and are not this method's
     * business — they are `base_uri`'s other two declared states, and both answer `false` here.
     */
    public static function isAbsolute(string|bool|null $baseUri): bool
    {
        if (! is_string($baseUri) || trim($baseUri) === '') {
            return false;
        }

        $parts = parse_url(trim($baseUri));

        return is_array($parts)
            && ($parts['scheme'] ?? '') !== ''
            && ($parts['host'] ?? '') !== '';
    }

    /**
     * The host of a declared authority, or null when it names none.
     */
    public static function hostOf(string|bool|null $baseUri): ?string
    {
        if (! self::isAbsolute($baseUri)) {
            return null;
        }

        $host = parse_url(trim((string) $baseUri), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * The ORIGIN of an absolute URI — `scheme://host[:port]` — or null when it names none.
     *
     * `hostOf()` deliberately drops the scheme and the port, which is right for deciding what domain
     * a route is constrained to and wrong for deciding whether two URIs come from the same place:
     * `http://a.test` and `https://a.test:8443` share a host and are different origins. Beam-facade
     * ticket 104 needs the second question, so it gets its own method rather than a second reading
     * of the first.
     *
     * Applies to any absolute URI, not just a declared `base_uri` — a schema `$id` is the other
     * caller. That is deliberate and it is the whole reason this stays STRUCTURAL: the method is
     * told nothing about tenants, hosts or ownership, so 64's rule that this package stays ignorant
     * of WHICH authority anyone claims is preserved. The comparison of two origins, and the meaning
     * anyone attaches to their being equal, belongs to the caller — for 104 that is
     * {@see \Splicewire\Tower\Data\SchemaRegistryFreezeInputData}, which is where knowledge of
     * what a tenant is is allowed to live.
     */
    public static function originOf(string|bool|null $uri): ?string
    {
        if (! self::isAbsolute($uri)) {
            return null;
        }

        $parts = parse_url(trim((string) $uri));

        if (! is_array($parts)) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        return isset($parts['port']) ? $origin.':'.$parts['port'] : $origin;
    }

    /**
     * The path of a declared authority, trimmed of slashes — `''` for a path-less authority.
     */
    public static function pathOf(string|bool|null $baseUri): string
    {
        if (! self::isAbsolute($baseUri)) {
            return '';
        }

        return trim((string) (parse_url(trim((string) $baseUri), PHP_URL_PATH) ?? ''), '/');
    }
}
