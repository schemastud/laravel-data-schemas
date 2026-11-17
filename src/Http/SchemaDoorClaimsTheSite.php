<?php

namespace Schemastud\DataSchemas\Http;

use LogicException;

/**
 * Thrown at boot when the public schema door would be a catch-all over the app's WHOLE SITE (launch ticket 70).
 *
 * A path-less `data-schemas.base_uri` mounts the door as a root `{path}` catch-all. That is the supported shape
 * for a host that serves NOTHING BUT schemas: the door is domain-constrained to it (see {@see SchemaDoorMount})
 * and answers there and nowhere else. It claims the whole site instead when the tier it mounts on is
 * unconstrained (`domain => null`) or constrained to the app's OWN host, which the SchemaDoorMount docblock calls
 * "never what a host declaring a dedicated `schemas.` subdomain meant". It then shadows every later route, and a
 * package that refuses shadowed mounts (laravel-beam's ApiRoot) fails the boot with an error that names the door
 * but not the setting. So it is refused here, before any route is registered, naming the setting and the fix.
 *
 * The message carries the authority's ORIGIN only (never userinfo, path or query), and names the config key
 * plus the env variable the starters wire it from.
 */
class SchemaDoorClaimsTheSite extends LogicException
{
    public function __construct(public string $origin, public string $tier, public ?string $domain)
    {
        parent::__construct(sprintf(
            '"data-schemas.base_uri" (SCHEMA_BASE_URI in the starters) is the path-less origin %s, so the schema '
            .'door is a root catch-all, and its "%s" tier would mount it on %s: a catch-all over the whole site. '
            .'Give the door a path, e.g. SCHEMA_BASE_URI=%s/schemas. (A bare origin is only for a host that '
            .'serves nothing but schemas, constrained to that host.)',
            $origin,
            $tier,
            $domain === null ? 'EVERY host (the tier declares domain => null)' : "the app's own host [{$domain}]",
            $origin,
        ));
    }
}
