# 0004 — An undeclared title is absent where a host says so

- Status: Accepted (app-walkthrough APP-09, the SPEC row decided at splicewire-ecosystem `04de2be0`)
- Date: 2026-10-05
- Decides: the host-gated reversal the SPEC names under APP-21
  (`~/Workspaces/splicewire-ecosystem/.scratch/splicewire/tower/app-walkthrough/SPEC.md`, §1.2 "Unsettled"
  and APP-21; raised in its sidebar `B-A3.md`, A3-1)

## Context

An object or enum schema always carried a `title`. With no class-level `#[Title]`, the generator used the class
short name (`buildObjectSchema()` and `ensureEnumDef()` in `src/Generators/JsonSchemaGenerator.php`). The enum
fallback was documented as deliberate: "Falls back to the short name, unchanged, for every enum that hasn't opted
in."

Renderers read `title` as a display label, so the short name leaked to users. The app walkthrough found
`UxType`, `StatusEnum`-style names and `…Data` class names rendered as labels (FINDINGS 19–21). Its rule (APP-21) is
that a rendered label comes only from declared display metadata: `#[Title]`/`#[Description]`, a
`ProvidesEnumLabel::label()`, or a host label map. With none declared, a renderer shows nothing, or, for a property,
its humanized name.

Third-party hosts may depend on today's output. A title is also outside `SchemaFingerprint::VOLATILE_KEYS`'s
structural hash, so changing it never registers as drift. That makes it safe for frozen versions, but invisible to
the drift guard as a behaviour change.

## Decision

**A host switch, default unchanged.** `schema_metadata.identifier_titles`:

- `true` (the package default): today's output, byte for byte. An undeclared object or enum is titled with its
  short name.
- `false`: an object or enum with no `#[Title]` carries **no** `title`.
  - A declared `#[Title]` is emitted as before.
  - A `ProvidesEnumLabel` enum still emits `enumNames`.
  - Properties are untouched.

Tower and the flagship set `false` (APP-09). Every other host keeps the default until it opts in.

## Consequences

- A host that opts in regenerates its committed schema artifacts once. Titles drop out of the bodies, and the
  structural fingerprints do not move, so no version bump or re-freeze is needed.
- Renderers must not depend on `title` being present. They go through one helper (`displayLabel(schema, key)` in js
  schemastud), which falls back only to a humanized property name.
- Where the old title was the only label, a class that wants one now declares `#[Title]`, or `ProvidesEnumLabel` for
  an enum. The doctor check `schema.identifier-label` (laravel-beam) counts the classes reachable from a particle's
  `input:`/`output:` that still declare none, and each host ratchets it down.
