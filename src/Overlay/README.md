# DataOverlay fold

The array-native core of DataOverlay (ADR-0089 / `.scratch/data-json-overlays/SPEC.md`): an
ordered stack of overlay documents laid over a whole JSON document — a schema *or* an instance —
through one shared format. This slice lands the addressing substrate, the fold loop, the document
contract, and the first op (`override`).

## JSONPath library choice — `galbar/jsonpath` (Apache-2.0)

DataOverlay is a **dialect**: RFC 9535 JSONPath for addressing × the OpenAPI Overlay 1.0.0 document
shape × a coined op delta (`override`/`merge`/`unset`). The addressing half needs a real JSONPath
engine (literal paths, wildcards, filters). Candidates evaluated:

| library | addressing | fit for a *fold* |
|---|---|---|
| `softcreatr/jsonpath` (`Flow\JSONPath`, MIT) | RFC 9535 | **query-only** — `find()` returns matched *values*, never their locations, so there is no way to write the result back. Rejected. |
| **`galbar/jsonpath` (`JsonPath\JsonPath`, Apache-2.0)** | wildcards, filters, slices, recursive descent | `JsonPath::get(&$root, $path)` returns **references into the document**. Assigning to a returned reference mutates the base in place — exactly what an override/merge fold needs. **Chosen.** |

`galbar` also offers a `$createInexistent` flag, but it fabricates nodes *through* wildcards
(`$.a.*.b` invents `b` under every child). So create-absent is **not** delegated to the library:
`ConcreteTarget` decides whether a no-match target is a concrete path and, only then, walks/creates
it. The library is used purely to resolve matches of *existing* nodes.

The engine is isolated behind `JsonPathMutator` — the fold loop (`OverlayStack`) never touches it —
so the library can be swapped without disturbing the format or op semantics.
