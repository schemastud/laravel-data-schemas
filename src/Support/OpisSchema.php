<?php

namespace Schemastud\DataSchemas\Support;

use Schemastud\DataSchemas\Migration\AcceptanceGate;

/**
 * Preparation every opis-backed validation door in the estate shares (beam-facade ticket 51).
 *
 * `opis/json-schema` resolves a document's `$id` as a URI: an ABSOLUTE one is fine, a RELATIVE one
 * (`waitlist/1`, the shape a bare form ref stems to) makes it throw while parsing, before it has
 * looked at the candidate at all. The two gates disagreed about this for as long as both existed —
 * beam's formatted door path stripped a relative `$id` and {@see AcceptanceGate} did not, so the same
 * schema produced a 422-with-field-errors at one door and, through the gate's deliberate fail-closed
 * `catch`, a *does-not-conform* verdict at the other: a 500 on a correct payload.
 *
 * The fix is shared preparation rather than a second `catch`. The gate's fail-closed posture is
 * intact and deliberate ("a schema opis cannot parse cannot vouch for a candidate") — what changed
 * is that a relative `$id` is no longer a thing opis cannot parse.
 *
 * Dropping the `$id` is safe because both doors validate the target shape IN PLACE, never by
 * reference: nothing resolves a `$ref` against the document's own base URI. An absolute `$id` is
 * left alone, so a registry-addressed artifact keeps its identity through validation.
 */
final class OpisSchema
{
    /**
     * The schema document with a relative `$id` removed, ready to hand to opis.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function withoutRelativeId(array $schema): array
    {
        $id = $schema['$id'] ?? null;

        if (is_string($id) && ! str_contains($id, '://')) {
            unset($schema['$id']);
        }

        return $schema;
    }
}
