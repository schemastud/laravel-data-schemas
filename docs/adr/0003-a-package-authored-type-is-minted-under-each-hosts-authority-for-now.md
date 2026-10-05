# 0003 — A package-authored type is minted under each host's authority, for now

- Status: Accepted (owner ruling 2026-10-05, "recs", relayed by the integrator at 09:24Z)
- Date: 2026-10-05
- Decides: app-walkthrough OQ-A3
  (`~/Workspaces/splicewire-ecosystem/.scratch/splicewire/tower/app-walkthrough/SPEC.md`, decided at
  splicewire-ecosystem `04de2be0`; the question was raised in its sidebar `B-A3.md`, APP-A3-7)
- Related: flagship ADR-0191 (`~/Herd/splicewire-app/docs/adr/0191-…`, one namespace-URI parser), the
  territory the SPEC names for this question

## Context

A class that implements `SchemaIdentity` gets its `$id` from the host's `data-schemas.base_uri`:
`JsonSchemaGenerator::versionedId()` resolves `<schemaName()>/<schemaVersion()>` against the declared
base (`src/Generators/JsonSchemaGenerator.php:1207-1248`). The base has no package default
(`config/data-schemas.php:224`, `'base_uri' => null`). When it is unset the generator throws, when it is
`false` it mints no versioned id, and when it is not an absolute origin it refuses
(`JsonSchemaGenerator.php:1213-1230`).

That rule was written for the host's own types, and it holds for them. It also applies, unchanged, to
types a package authors. `Rushing\Commerce\Data\Invoice` declares `schemaName()` `commerce/invoice`
(`laravel-commerce/src/Data/Invoice.php:61-69`). The SPEC calls it beam-commerce's `invoice`. The
flagship has frozen it as `https://app.splicewire.com/schemas/commerce/invoice/1`
(`resources/schemas/lifecycle/https-app-splicewire-com-schemas-commerce-invoice-1.*.schema.json`). Any
other host that freezes the same class gets the same path under its own base, so it gets a different
`$id` for the same shape.

Today that fork is latent. The flagship is the only host with frozen artifacts: 48 under
`resources/schemas/lifecycle/`, against zero in each of the five starters. But a hub and its satellites
would name one package type by different ids, and APP-26(b) ("content and tenant schemas carry the host
or tenant authority") would be the only rule that says which ids are host-owned.

## Decision

**Keep host minting.** A package-authored type is minted under each host's declared `base_uri`, exactly
as today. This ADR changes no code and no artifact.

Content and tenant schemas are host-minted under every answer to the question below. The package-type
question does not touch them.

## Open question: package authority

The alternative is not adopted. It is recorded so the next reader starts from it rather than
rediscovering it.

- **What it would mean.** A package type carries an authority its package declares, rather than the
  host's base. One `$id` per package type, at every host.
- **What would change with it.**
  - APP-26(b) narrows to content and tenant schemas.
  - The hub/satellite identity of package types stops forking.
  - Someone must serve the package authority's origin, because a `$id` here is a promise to answer
    there. That is the reason `base_uri` has no default.
- **What would reopen it.** Any of:
  - a second host freezing a package type whose ids must compare equal to another host's, such as a
    satellite validating a hub's payload by `$id`;
  - a cross-host registry or federation that keys on `$id`;
  - a package wanting to publish its schemas at an origin it controls.

  Until one of these happens, per-host ids cost nothing that is measured.

## Consequences

- No migration, and no change to `versionedId()` or `config/data-schemas.php`.
- APP-13 proceeds on APP-26 (a) and (c) as written. Its package-type half is settled by this ADR as "host
  minting", so nothing in APP-13 waits on OQ-A3.
- A host that freezes package types records them under its own authority. A reader comparing two hosts'
  artifacts should expect different `$id`s for the same package class.
