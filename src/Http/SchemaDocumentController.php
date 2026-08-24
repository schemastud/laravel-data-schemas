<?php

namespace Schemastud\DataSchemas\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
    public function __invoke(Request $request, ServedSchemaRegistry $registry): Response
    {
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
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $etag,
        ];

        if (in_array($etag, $request->getETags(), true) || $request->header('If-None-Match') === '*') {
            return response('', 304, $headers);
        }

        return response($body, 200, $headers);
    }
}
