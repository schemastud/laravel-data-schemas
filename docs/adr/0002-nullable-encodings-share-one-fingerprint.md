# 0002 — The two nullable encodings share one fingerprint

- Status: Accepted
- Date: 2026-09-25
- Amends: [0001](0001-schema-lifecycle-versioning-migration-seam.md) §fingerprinting

## Context

`e66c503` changed how the generator spells a nullable Data reference. Before it, `?Split $split`
projected to `{"$ref": ".../split/1", "nullable": true}`. `nullable` is an OpenAPI 3.0 keyword, and a
2020-12 validator ignores it, so that artifact rejected `null`. After it, the same declaration projects
to `{"anyOf": [{"$ref": ".../split/1"}, {"type": "null"}]}`.

The PHP classes did not change. But every version-1 artifact frozen before `e66c503` still has the
old spelling, and the drift guard compared fingerprints byte for byte. So the flagship's drift gate
reported six `Rushing\Commerce\Data\*` classes as changed shape without a version bump (Gift, Order,
Payment, Purchase, Settlement and VaultedPaymentMethod; triage
`.scratch/splicewire/splicewire-app/suite-green/TRIAGE.md`, cluster E). `schema:freeze` would also
have thrown `SchemaRegistryConflict` on them, because the registry's write-once check uses the same
fingerprint.

The alternative was to bump each of the six to v2 and add a payload migration. Those migrations would
have been identity functions: a stored payload is the same under either spelling.

## Decision

`SchemaFingerprint::canonicalize()` rewrites a boolean `nullable` keyword into the null alternative the
generator emits today (`SchemaFingerprint::normalizeNullable()`, which mirrors
`JsonSchemaGenerator::makeNullable()`):

- `$ref` becomes `anyOf: [{$ref}, {type: null}]`, and sibling keywords are kept.
- An existing `anyOf` gains a `{type: null}` member.
- A scalar or array `type` becomes a union that includes `"null"`.
- An `enum` gains `null`.
- `nullable: false` is the default, so it is dropped.

The rewrite happens before key sorting and hashing. Because of this:

- The drift guard, the inert-default predicate (api-surface-coherence 122) and the registry's
  write-once check all see the two spellings as one identity.
- Freezing today's projection over an old-spelling artifact is an idempotent no-op. The stored file
  keeps the spelling it was frozen with, because artifacts are write-once.

Only the encoding is folded. Losing or gaining nullability, or pointing a `$ref` somewhere else, is
still drift. `NullableEncodingEquivalenceTest` asserts both halves.

## Consequences

- The six commerce artifacts stay at version 1 with no migration. Order's other delta, added `default`
  keywords, is already inert on a version-1 artifact under api-surface-coherence 122.
- A stored v1 artifact in the old spelling still validates as it always did: a strict 2020-12
  validator reading it directly will reject `null` on those fields. This ADR changes identity, not the
  stored bytes. Any consumer that validates a pinned read against the frozen file, instead of the live
  projection, inherits that pre-existing narrowness.
- If a future generator change re-spells some other keyword, it needs its own ruling. This ADR does
  not license treating encoding changes as drift-free in general.
