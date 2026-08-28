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

## The registry (`LensRegistry`)

Declared lenses are enumerable. A `LensRegistration` carries a `key` (dotted `vendor.lens-name`, e.g.
`audiostud.song-to-timeline` — *not* the association's `@id`, since two lenses legitimately share one
canonical), a **tier**, the association (or a closure building it lazily), and a `LensEvidence` set.

**Keys are dotted, and the `@id` beside them is not.** `LensRegistry` implements the estate's `Registry`
contract (it *holds* a `BasicRegistry`; it does not extend one), so a key is a `RegistryKey` under the
`schemas.lenses` root and `/` is not a legal key character — a slashed spelling would have cost a second
key type to keep one punctuation mark. An association's `@id` (`audiostud/timeline-otio`) is a schema
identifier rather than an address and keeps its slash unchanged. A malformed key is refused at
registration, not at read.

Two `keys()`-shaped reads, deliberately named apart: `keys()` is the contract's and returns absolute
`RegistryKey`s (`schemas.lenses.audiostud.song-to-timeline`); `lensKeys()` is this registry's own
vocabulary and returns the bare relative strings.

| tier | claim |
|---|---|
| `host-applied` | one host's carrier convenience — visible fleet-wide, authoritative nowhere |
| `engine-authoritative` | the engine's own binding for that `@id` — the projection every host is measured against |

Register from your own provider's `boot()`; the registry is a container singleton and describes itself
into beam's manifest index (`splicewire:beam:manifests --json`) when a beam host is present.

**Fidelity is certified here, never claimed.** There is deliberately no `fidelity()` reader on a
registration: `LensRegistry::certifiedFidelity($key)` exercises the laws against the submitted evidence,
and an **empty** evidence set certifies `lossy` — the laws over zero samples are vacuously true, so
"submitted nothing" must not read as "survived everything". Registration is discoverability only: nothing
dispatches through the registry, so a host-applied lens becomes *visible* without becoming authoritative.

## Vendor seam (ADR-0092)

This **mechanism** is fully-open (`schemastud/laravel-data-schemas`). **Applied lenses**
(prosemirror↔blockdoc, music-profile↔OTIO) and the `ContentSource` mount are paid (`splicewire/*`).
There is no `*-facade` package.
