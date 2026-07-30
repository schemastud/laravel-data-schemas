# Directed lens — the reversible arm of DataOverlay

The forward-only DataOverlay fold (`../README.md`) lays deltas over a document one way: canonical →
rendering. A **facade** is its bidirectional, fidelity-typed generalisation (ADR-0155, companion to
ADR-0089) — the same addressing + registry + stack, plus a declared *return trip*. No new package: this
lives in place, alongside the fold it extends.

## The unit: a `LensAssociation`

A declared lens carries the **committed core-5** + a **reserved complement**:

| field | meaning |
|---|---|
| `id` | JSON-LD `@id` of the canonical (ADOPT) |
| `locator` | JSON Pointer / JSONPath addressing the rendering (ADOPT) |
| `direction` | which side is canonical, so a resolver tells `get` from `put` |
| `fidelity` | `lossless-eligible \| lossy` — a *claim*, proved or downgraded, never trusted |
| `get`/`put` | ADR-0038 `project()`/`hydrate()` made reversible (`get` total, `put` best-effort) |
| `complement` | *reserved* — rendering-private state a strict projection drops (null for the near-bijective case) |

## The laws (`LensLaws`)

A pair is *lossless-eligible* iff its lens obeys **GetPut** (`put(get(S), S) = S`) and **PutGet**
(`get(put(V, S)) = V`). Three satisfiable cases: **bijective**, **asymmetric** (`put` reconstructs from
the retained prior canonical), and **symmetric-with-complement** (`put` reconstructs from canonical +
complement). If none holds, the pair stays **lossy**.

## The resolver (`ReversibleResolver`)

- `get(assoc, canonical)` — canonical → rendering. **Null association = identity embed** (byte-identical,
  the free case).
- `put(assoc, rendering, priorCanonical)` — rendering → canonical, reconstructing dropped state from the
  retained prior canonical (+ complement).
- `certify(assoc, samples)` — exercises the laws and **downgrades a law-breaking lens to `lossy`**. It
  relabels the claim; it never corrupts content.

## Vendor seam (ADR-0092)

This **mechanism** is fully-open (`schemastud/laravel-data-schemas`). **Applied lenses**
(prosemirror↔blockdoc, music-profile↔OTIO) and the `ContentSource` mount are paid (`splicewire/*`).
There is no `*-facade` package.
