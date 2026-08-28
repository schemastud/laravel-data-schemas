<?php

namespace Schemastud\DataSchemas\Http;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Schemastud\DataSchemas\Contracts\SchemaRegistry as SchemaRegistryContract;
use Schemastud\DataSchemas\Contracts\ServedSchemaRegistry;

/**
 * The public schema door: `GET <$id>` returns the artifact (beam-facade ticket 82).
 *
 * Ticket 64 ruled the `$id` authority is the origin that serves the schema, and landed the identity
 * half across seven hosts; nothing dereferenced it, so the capability the ruling named did not exist.
 * This is that capability, and it is small because the registry was already keyed by the exact
 * absolute `$id` string — the inversion is concatenation, not parsing.
 *
 * THE `$id` IS THE REQUEST URL. Not `base_uri` plus the path — the URL as asked for. Three things
 * follow, and they are the reason the door needs no domain constraint:
 *
 *  1. A document is only ever served at the URI that IS its identity. Asking on a tenant subdomain
 *     reconstructs the tenant's authority and finds nothing, rather than serving the central
 *     artifact under a hostname it does not name.
 *  2. The extension path stays open. When tenant-registered schemas are served (pending an ownership
 *     check on registration — ticket 64 left that hole deliberately open), it is this door with
 *     another registry behind it, not a second door with a second identity scheme.
 *  3. The conformance audit that follows compares two INDEPENDENTLY derived values — the authority an
 *     artifact was minted with, and the authority that answers — instead of checking config against
 *     itself.
 *
 * The response is the BARE document. Both surfaces that existed before this returned
 * `ResponseBody::from(['data' => ...])`, which a `$ref`-following client cannot consume: it fetches a
 * URI and expects a schema at it. No query parameters are honoured — `?resolve=inline` is a second
 * way to say the same thing, and a consumer that follows refs does its own following.
 */
class SchemaDocumentController
{
    /**
     * The route default carrying which {@see ServedTier} matched. It rides on the ROUTE rather than in
     * the container because *which tier answered* is a property of what matched, and the discriminator
     * is the domain — a container binding would have to re-derive that from the request, badly.
     */
    public const TIER = 'schemaTier';

    /**
     * ⚠️ **Two things that used to be constants here are now properties of the matched tier: the
     * registry, and the `Cache-Control` header** (beam-facade 170 + 180).
     *
     * The injected {@see ServedSchemaRegistry} remains the default and is what a host with one tier
     * still gets. A tier declaring its own `registry` container key is resolved **per request**, never
     * held — a tenant tier must resolve against whatever connection its middleware has just established,
     * and an instance captured at boot would be bound to the central one forever.
     */
    public function __invoke(Request $request, ServedSchemaRegistry $registry, Container $container, ConfigRepository $config): Response
    {
        $tier = $this->tier($request, $config);

        if ($tier->registry !== ServedSchemaRegistry::class) {
            $resolved = $container->make($tier->registry);

            if (! $resolved instanceof SchemaRegistryContract) {
                // A misdeclared tier must not silently fall back to the host tier's registry — that
                // would serve the WRONG population under the tenant's own URL, which is the failure
                // this whole seam exists to prevent. Refuse the request instead.
                return response('', 500, ['Cache-Control' => 'no-store']);
            }

            $registry = $resolved;
        }

        $schema = $registry->get($request->url());

        if ($schema === null) {
            // An artifact may be registered later, so a miss must never be cached.
            return response('', 404, [
                'Cache-Control' => 'no-store',
            ]);
        }

        $body = (string) json_encode(
            $schema,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        // A STRONG etag, over the served bytes. Not SchemaFingerprint, which deliberately excludes
        // `$id` and other document chrome — correct for drift detection, wrong for byte identity.
        $etag = '"'.hash('sha256', $body).'"';

        // `immutable` is ordinarily a hopeful hint. Here it is simply true: the registry is write-once
        // behind a structural fingerprint guard, so an artifact at a given `$id` can never legally
        // change. That is also what makes a CDN in front of this door correct without invalidation.
        $headers = [
            'Content-Type' => 'application/schema+json',
            // NOT a constant since 180. `public` authorizes any shared cache to store this response and
            // hand it to the next caller — correct for a host's own artifacts, a tenant disclosure for
            // anything else. The tier that matched says which directive it answers with.
            'Cache-Control' => $tier->cache,
            'ETag' => $etag,
        ];

        if (in_array($etag, $request->getETags(), true) || $request->header('If-None-Match') === '*') {
            return response('', 304, $headers);
        }

        return response($body, 200, $headers);
    }

    /**
     * The tier that matched, from the route's own default. Falls back to the synthesized host tier when
     * the default is absent — which is every route registered before this seam existed, and every test
     * that builds a request without going through {@see \Schemastud\DataSchemas\LaravelDataSchemasServiceProvider::mountSchemaDoor()}.
     * The fallback is the PUBLIC tier deliberately: it is the historical behaviour, and a tenant tier
     * that failed to tag its route would rather 404 against the host registry than serve tenant bytes
     * under a public header.
     */
    private function tier(Request $request, ConfigRepository $config): ServedTier
    {
        $key = $request->route()?->defaults[self::TIER] ?? null;

        foreach (ServedTier::declared($config->get('data-schemas.served_tiers'), null) as $tier) {
            if ($tier->key === $key) {
                return $tier;
            }
        }

        return new ServedTier(ServedTier::DEFAULT_KEY);
    }
}
