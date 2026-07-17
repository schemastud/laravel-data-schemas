<?php

namespace Schemastud\DataSchemas\Overlay;

use JsonPath\JsonPath;

// The write primitive behind the fold. It wraps the wired RFC 9535 JSONPath
// engine (galbar/jsonpath — chosen because its `JsonPath::get(&$root, …)`
// returns *references* into the document, which is what lets an overlay op
// mutate in place; query-only engines cannot). All op semantics live here so
// the fold loop (OverlayStack) stays a dumb ordered driver.
//
// See src/Overlay/README.md for the library-choice note.
class JsonPathMutator
{
    // `override`: wholesale-replace the value at every node the target matches;
    // when the target matches nothing, upsert it — but only if the target is a
    // concrete path, since RFC 9535 addresses existing nodes and a wildcard
    // create would fabricate nodes. No recursion (the value replaces, it does
    // not deep-merge).
    public function override(array &$doc, string $target, mixed $value): void
    {
        [$refs] = JsonPath::get($doc, $target, false);

        if (! empty($refs)) {
            foreach ($refs as &$ref) {
                $ref = $value;
            }
            unset($ref);

            return;
        }

        $segments = ConcreteTarget::parse($target);

        if ($segments === null) {
            throw new OverlayException(
                "override target '{$target}' matched no node and is not a concrete path; ".
                'create-absent requires a concrete parent path plus a literal child key.'
            );
        }

        ConcreteTarget::set($doc, $segments, $value);
    }
}
