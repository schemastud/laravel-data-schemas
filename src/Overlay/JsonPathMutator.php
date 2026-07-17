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

    // `merge`: unbounded-recursive RFC 7386 deep-merge at every matched node.
    // Recursion stops at scalars; arrays (lists) replace wholesale — element
    // edits go through JSONPath-addressed actions, not a merge-key. Unlike raw
    // RFC 7386, `null` sets a node to null rather than deleting it; deletion is
    // the distinct `unset` op. Idempotent under re-fold. No create-absent: merge
    // folds into what exists (upsert is `override`'s job).
    public function merge(array &$doc, string $target, mixed $value): void
    {
        [$refs] = JsonPath::get($doc, $target, false);

        foreach ($refs as &$ref) {
            $ref = $this->deepMerge($ref, $value);
        }
        unset($ref);
    }

    // `unset`: dumb node-delete — remove exactly the matched node(s), nothing
    // else. No cascade (a dangling `required`/`$ref` is the author's to scrub
    // with companion unsets). galbar hands back value-references, not the
    // parent slot, so matched nodes are tagged with a unique sentinel and swept
    // out; a list that loses an element is re-indexed so it stays a JSON array.
    public function unset(array &$doc, string $target): void
    {
        [$refs] = JsonPath::get($doc, $target, false);

        if (empty($refs)) {
            return;
        }

        $sentinel = new \stdClass;

        foreach ($refs as &$ref) {
            $ref = $sentinel;
        }
        unset($ref);

        $this->purge($doc, $sentinel);
    }

    protected function deepMerge(mixed $target, mixed $patch): mixed
    {
        // A scalar or a list patch replaces the target wholesale (recursion
        // stops here — arrays are treated as scalar leaves).
        if (! is_array($patch) || array_is_list($patch)) {
            return $patch;
        }

        // An object patch folds key-by-key. A non-object target is overwritten
        // to an object first (RFC 7386).
        if (! is_array($target) || array_is_list($target)) {
            $target = [];
        }

        foreach ($patch as $key => $value) {
            $target[$key] = $this->deepMerge($target[$key] ?? null, $value);
        }

        return $target;
    }

    protected function purge(array &$node, \stdClass $sentinel): void
    {
        $wasList = array_is_list($node);
        $removed = false;

        foreach ($node as $key => $value) {
            if ($value === $sentinel) {
                unset($node[$key]);
                $removed = true;
            }
        }

        if ($wasList && $removed) {
            $node = array_values($node);
        }

        foreach ($node as &$value) {
            if (is_array($value)) {
                $this->purge($value, $sentinel);
            }
        }
        unset($value);
    }
}
