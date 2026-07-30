<?php

namespace Schemastud\DataSchemas\Overlay\Lens;

// The well-behavedness laws that make a directed lens *lossless-eligible*, and
// nothing more. A lens is only ever eligible until it is exercised: these
// checks run the round-trip against representative values and report whether the
// two laws hold. A lens that fails is honestly downgraded to `Fidelity::Lossy`
// (by the resolver) rather than trusted — the facade never silently corrupts.
//
//   - GetPut: put(get(S), S) = S  — projecting the canonical then folding the
//     un-edited rendering back reconstructs the canonical exactly.
//   - PutGet: get(put(V, S)) = V  — folding an edited rendering back then
//     re-projecting yields the edit.
//
// A complementing lens derives its complement from the round-trip itself, so
// the caller need not supply one.
class LensLaws
{
    // GetPut against a single canonical sample.
    public static function getPut(DirectedLens $lens, mixed $canonical, mixed $complement = null): bool
    {
        $rendering = $lens->get($canonical);
        $complement = self::complementFor($lens, $rendering, $canonical, $complement);

        return self::equal($lens->put($rendering, $canonical, $complement), $canonical);
    }

    // PutGet against a single edited-rendering sample folded onto a prior
    // canonical.
    public static function putGet(DirectedLens $lens, mixed $rendering, mixed $priorCanonical, mixed $complement = null): bool
    {
        $complement = self::complementFor($lens, $rendering, $priorCanonical, $complement);
        $canonical = $lens->put($rendering, $priorCanonical, $complement);

        return self::equal($lens->get($canonical), $rendering);
    }

    protected static function complementFor(DirectedLens $lens, mixed $rendering, mixed $canonical, mixed $complement): mixed
    {
        if ($complement !== null) {
            return $complement;
        }

        return $lens instanceof ComplementingLens
            ? $lens->complementOf($rendering, $canonical)
            : null;
    }

    // Content equality tolerant of associative-key reordering (a lens that
    // reshuffles object keys is still lossless) but strict on list order and
    // scalar values. Lists stay ordered; objects are compared key-set + values.
    protected static function equal(mixed $a, mixed $b): bool
    {
        return self::normalise($a) === self::normalise($b);
    }

    protected static function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map([self::class, 'normalise'], $value);
        }

        ksort($value);

        return array_map([self::class, 'normalise'], $value);
    }
}
