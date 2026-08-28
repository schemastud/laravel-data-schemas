<?php

namespace Schemastud\DataSchemas\Http;

use Schemastud\DataSchemas\Contracts\ServedSchemaRegistry;

/**
 * One tier the public schema door serves: a registry, the middleware guarding it, the cache directive
 * it answers with, and the domain it answers on (beam-facade tickets 170 + 180).
 *
 * ## Why the door grew tiers rather than a second door
 *
 * Ticket 82 mounted one door over one registry with one constant header, and that was right while every
 * served document was **a host's own public artifact**. Ticket 180 ruled a per-tenant tier is
 * **authenticated**, and the moment a second population is served the two things the controller treated
 * as constants — which registry answers, and what `Cache-Control` it answers with — become properties of
 * *which tier matched*.
 *
 * A second door was refused for the reason `SchemaDocumentController` already states: **the `$id` IS the
 * request URL**, so a tenant artifact's URL already carries the tenant's host. Tiers therefore separate
 * by **domain**, which falls out of the identity contract for free — no new identity scheme, no second
 * route name to keep in sync, and the host tier's mount is untouched.
 *
 * ## What this package does NOT own, by ruling
 *
 * **No auth posture and no tenancy dependency.** 180's ruling is explicit that authentication is *"not by
 * default out of the laravel-data-schemas package"*, and ticket 82's rule already forbids the tenancy
 * dependency. So a tier carries `middleware` as **host-declared class strings this package never
 * inspects**, and a tier's registry is a **container key this package never binds**. A host wiring a
 * tenant tier supplies both; this package supplies the seam and the ordering.
 *
 * That is why `middleware` is not validated here. A misspelled middleware class is a host's own boot
 * error with a clear message, and a package that type-checked it would be asserting a vocabulary it was
 * just ruled not to have.
 *
 * ## The default tier is today's behaviour, exactly
 *
 * A host that declares no tiers gets one synthesized from `base_uri` + `served_directories` +
 * `ServedSchemaRegistry`, bare, with {@see self::PUBLIC_IMMUTABLE}. **Upgrading changes nothing**, which
 * is the property that lets this ship into 20-odd roots without a sweep.
 *
 * ⚠️ **`public` is the directive that authorizes the leak, and it is correct on exactly one tier.** A
 * shared cache or CDN may store a `public` response and hand it to the next caller; on a per-tenant tier
 * that is a disclosure, which is the whole of ticket 180. The tenant tier's ruled directive is
 * `private, max-age=31536000, immutable` — `private` removes the authorization, and `immutable` stays
 * because it is *true*: the registry is write-once behind a structural fingerprint guard, so an artifact
 * at a given `$id` cannot legally change. `no-store` was considered and refused as over-strict: it
 * discards a real property to buy nothing and makes every `$ref`-follow a network round trip on a door
 * whose entire purpose is being dereferenced by machines.
 */
class ServedTier
{
    /** The host tier's directive, unchanged since ticket 82 and correct only for a host's own artifacts. */
    public const PUBLIC_IMMUTABLE = 'public, max-age=31536000, immutable';

    /** The route name the door has always carried; the default tier keeps it so nothing citing it breaks. */
    public const DEFAULT_KEY = 'host';

    /**
     * @param  string  $key  the tier's name, used to derive its route name and to tag the matched route
     * @param  string  $registry  a CONTAINER KEY resolved per request — never an instance, so a tenant tier
     *                            resolves against whatever connection the middleware has established
     * @param  list<string>  $middleware  host-declared, never inspected here (see the class docblock)
     * @param  string  $cache  the `Cache-Control` value this tier answers with
     * @param  string|null  $domain  the host this tier answers on; null mounts unconstrained
     */
    public function __construct(
        public string $key,
        public string $registry = ServedSchemaRegistry::class,
        public array $middleware = [],
        public string $cache = self::PUBLIC_IMMUTABLE,
        public ?string $domain = null,
    ) {}

    /** The route name for this tier. The default tier keeps the historical name verbatim. */
    public function routeName(): string
    {
        return $this->key === self::DEFAULT_KEY
            ? 'data-schemas.document'
            : 'data-schemas.document.'.$this->key;
    }

    /**
     * Whether this tier's response may be stored by a shared cache. Not used for control flow — it
     * exists so a conformance check can ask the question of a *declaration* rather than parsing a header
     * string at runtime, which is how 180's disclosure would be caught if a host ever declares a tenant
     * tier `public` by hand.
     */
    public function isSharedCacheable(): bool
    {
        return str_contains(strtolower($this->cache), 'public');
    }

    /**
     * Every declared tier, in mount order, or the single synthesized default when a host declares none.
     *
     * ⚠️ **Order is mount order and mount order is not match order.** Laravel indexes domain-constrained
     * routes in a separate bucket that `RouteCollection::get()` merges *ahead* of the undomained ones, so
     * a domained tenant tier is tried before an undomained host tier regardless of what this list says —
     * which is the behaviour we want and is the same mechanism {@see SchemaDoorMount} relies on for the
     * path-less case. Declaring a tier first does not make it win; giving it a domain does.
     *
     * @param  mixed  $declared  the raw `data-schemas.served_tiers` config value
     * @param  string|null  $defaultDomain  the domain the host tier mounts on, from {@see SchemaDoorMount::domainFor()}
     * @return list<self>
     */
    public static function declared(mixed $declared, ?string $defaultDomain): array
    {
        if (! is_array($declared) || $declared === []) {
            return [new self(self::DEFAULT_KEY, domain: $defaultDomain)];
        }

        $tiers = [];

        foreach ($declared as $key => $tier) {
            if (! is_array($tier)) {
                continue;
            }

            $key = (string) $key;

            $tiers[] = new self(
                key: $key,
                registry: is_string($tier['registry'] ?? null) ? $tier['registry'] : ServedSchemaRegistry::class,
                middleware: array_values(array_filter((array) ($tier['middleware'] ?? []), 'is_string')),
                cache: is_string($tier['cache'] ?? null) ? $tier['cache'] : self::PUBLIC_IMMUTABLE,
                // A tier may mount unconstrained by declaring `domain => null` EXPLICITLY; the default
                // domain applies only when the key is absent, so "unconstrained" stays sayable.
                domain: array_key_exists('domain', $tier)
                    ? (is_string($tier['domain']) ? $tier['domain'] : null)
                    : $defaultDomain,
            );
        }

        return $tiers === [] ? [new self(self::DEFAULT_KEY, domain: $defaultDomain)] : $tiers;
    }
}
