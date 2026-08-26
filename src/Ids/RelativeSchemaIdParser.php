<?php

namespace Schemastud\DataSchemas\Ids;

use Schemastud\DataSchemas\Support\SchemaAuthority;
use Schemastud\JsonNs\NamespaceUri;

/**
 * The estate's own ref grammar: an absolute `$id` is itself, and anything else is RELATIVE to the
 * host's declared authority (beam-facade ticket 140).
 *
 * `content-schema/food-safety/kitchen-log/1` at a host declaring
 * `base_uri = https://app.splicewire.com/schemas` resolves to
 * `https://app.splicewire.com/schemas/content-schema/food-safety/kitchen-log/1`. That is the whole
 * grammar, and it is what makes `ContentSchemaId` deletable in ticket 141: the class existed to
 * supply an authority, and a relative id does not need one supplied — the host already declared it.
 *
 * ## The parsing half was already here; only resolution is new
 *
 * {@see NamespaceUri::from()} is total and needs no scheme — it splits on the last `/` with a
 * `ctype_digit` tail test, so `content-schema/x/1` already yields the stem `content-schema/x` and
 * version 1. Nothing about the value object changes, and nothing may: ticket 113 keeps it pure.
 * What this class adds is the one step that needs a host — completing a relative ref against the
 * declared authority — which is why it lives in the tier that owns `base_uri` and not in the value
 * object.
 *
 * ## Resolution happens on WRITE
 *
 * Ticket 140's first design call. A relative ref is expanded BEFORE it reaches
 * {@see \Schemastud\DataSchemas\Contracts\SchemaRegistry::register()}, so every stored key stays
 * absolute and the relative form is an authoring convenience that never survives into storage.
 * Three things stay true because of that, and all three would have had to change under
 * resolve-on-read:
 *
 *  - {@see \Schemastud\DataSchemas\Http\SchemaDocumentController}'s contract — *"THE `$id` IS THE
 *    REQUEST URL"* — is untouched. A read-side design would have had to reconcile with that
 *    sentence or overturn it.
 *  - A `LIKE <base>/<namespace>/%` scope over a schema-id column keeps matching, with an ABSOLUTE
 *    prefix. That predicate is the tell: whichever way this call went decided its shape, and it is
 *    absolute.
 *  - The stem written alongside a registered artifact, and any version enumeration over it, keep
 *    reading one shape.
 *
 * Both shapes are deliberately NOT reachable in storage. A registry holding a mix of relative and
 * absolute keys would make every lookup ask which kind it was holding.
 *
 * ## The tri-state, answered at all three states
 *
 * The three answers differ, and the difference between the last two is the interesting one:
 *
 *  - **an absolute `base_uri`** — resolves. The normal case.
 *  - **unset** — {@see UnresolvableRelativeSchemaId}. There is nothing to resolve against and the
 *    host has not decided what there should be. An `$id` is write-once, so an undecided authority
 *    fails rather than falls back. (A declared-but-non-origin `base_uri` — ticket 112's `/schemas`
 *    — takes this same branch: it is a decision nobody finished making.)
 *  - **`false`** — the ref resolves to ITSELF, unchanged, as a bare relative namespace URI. This is
 *    the state that needed the most thought and it is not "throw, only differently". `false` means
 *    the host has *decided* it mints no versioned identity: `versionedId()` returns null and
 *    `SchemaIdentity` classes keep the short-name `$id`, which is itself a bare non-URL string, and
 *    no schema door is mounted, so nothing at such a host ever promises to answer at an address.
 *    A relative ref there is therefore already the whole identity — there is no authority to prepend
 *    and, crucially, none is MISSING. Prepending nothing and throwing "no authority" are the same
 *    observation in the unset case and opposite readings here: unset is *undecided*, `false` is
 *    *decided that there is none*. Returning the ref unchanged is what makes the ref grammar agree
 *    with the short-name `$id` regime the same host is already running.
 *
 *    ⚠️ This branch is a design answer, not a measured one. Ticket 140 checked on 2026-08-26: none
 *    of the four opted-out roots (`calcucrypt`, `fable-legacy`, `numero`, `thingsontv`) installs
 *    `splicewire/tower`, so no opted-out host mints content schemas today and nothing in the estate
 *    exercises this path. Re-argue it before relying on it, rather than reading it as measured.
 *
 * ## An absolute ref is returned untouched, in every state
 *
 * Including `false` and unset. An absolute ref needs no authority, so refusing it would make the
 * host's own state a reason to reject an id that is already complete — and it is what lets a caller
 * pass a mix of already-minted `$id`s and relative refs through one door.
 */
class RelativeSchemaIdParser implements SchemaIdParser
{
    /**
     * Total, by design — this is the grammar every ref falls back to when no registered parser
     * claims it. See {@see SchemaIdParser} for why that makes it the resolver's floor rather than an
     * entry in the ordered list.
     */
    public function handles(string $ref): bool
    {
        return true;
    }

    public function parse(string $ref, string|bool|null $baseUri): NamespaceUri
    {
        $ref = trim($ref);

        if (SchemaAuthority::isAbsolute($ref)) {
            return NamespaceUri::from($ref);
        }

        // Decided that there is no authority — the ref is already the whole identity.
        if ($baseUri === false) {
            return NamespaceUri::from(ltrim($ref, '/'));
        }

        if (! SchemaAuthority::isAbsolute($baseUri)) {
            throw new UnresolvableRelativeSchemaId($ref, $baseUri);
        }

        return NamespaceUri::from(
            rtrim(trim((string) $baseUri), '/').'/'.ltrim($ref, '/')
        );
    }
}
