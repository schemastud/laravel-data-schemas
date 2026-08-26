<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Schemastud\DataSchemas\Ids\RelativeSchemaIdParser;
use Schemastud\DataSchemas\Ids\SchemaIdParser;
use Schemastud\JsonNs\NamespaceUri;

/**
 * A SECOND implementation of the seam, shaped like the one beam-facade ticket 141 will register from
 * `splicewire/tower`: a bare slug (`demo/guest-intake`) is promoted into a namespace
 * (`content-schema/demo/guest-intake`) and a trailing `.json` is dropped.
 *
 * It lives here rather than in `src/` on purpose. Ticket 140 ships the seam and the estate's own
 * grammar; tower's belongs to tower. But a seam validated only by its own built-in is a seam with
 * one implementation, so the tests exercise it through a registrant that is genuinely somebody
 * else's — one that claims some refs and not others, and that takes the authority from the host
 * instead of carrying one.
 *
 * Note what it does NOT do: it never reads `config()`. It resolves through the default grammar with
 * the authority it was handed, which is the whole discipline the seam exists to enforce.
 */
class PrefixingSchemaIdParser implements SchemaIdParser
{
    public const NAMESPACE = 'content-schema';

    public function handles(string $ref): bool
    {
        // Only a BARE slug — two path segments, no scheme, no namespace prefix, no version tail.
        // Anything already spelled in full belongs to the default grammar.
        if (str_contains($ref, '://') || str_starts_with($ref, self::NAMESPACE.'/')) {
            return false;
        }

        return preg_match('#^[a-z0-9\-]+/[a-z0-9\-]+(\.json)?$#i', $ref) === 1;
    }

    public function parse(string $ref, string|bool|null $baseUri): NamespaceUri
    {
        $slug = preg_replace('/\.json$/i', '', $ref) ?? $ref;

        return (new RelativeSchemaIdParser)->parse(self::NAMESPACE.'/'.$slug, $baseUri);
    }
}
