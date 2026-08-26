# Changelog

All notable changes to `schemastud/laravel-data-schemas` are documented here.

## Unreleased

### Added
- **Relative schema ids, behind a `SchemaIdParser` strategy seam** (`src/Ids/`, beam-facade 140).
  `content-schema/food-safety/kitchen-log/1` is now a legal ref that resolves against the host's
  declared `data-schemas.base_uri`, so a package no longer has to invent an authority in order to
  have a ref grammar. `SchemaIdResolver` is the one config-aware step — the value objects
  (`SchemaId`, `NamespaceUri`) stay pure per ticket 113, and there is still **no default authority
  in any spelling**. Packages declare their own grammar by appending a `SchemaIdParser` to
  `config('data-schemas.id_parsers')` (a `PickOne` registry, `schemas.id-parsers`); the package's
  own absolute/relative grammar is the resolver's unshadowable floor rather than an entry in that
  list. Resolution happens on **write**, so every stored registry key stays absolute and
  `SchemaDocumentController`'s "the `$id` IS the request URL" contract is untouched. The `base_uri`
  tri-state gets three different answers: an origin resolves, unset throws
  `UnresolvableRelativeSchemaId` (ticket 112's defect reached from the ref side, *not* a reversal of
  it), and `false` — a host that has decided it mints no versioned identity — resolves a relative
  ref to itself. `MissingSchemaBaseUri`, `NonAbsoluteSchemaBaseUri` and `UnresolvableRelativeSchemaId`
  now share the `UndeclaredSchemaAuthority` marker so all three are catchable as one rule.
  `JsonSchemaGenerator::versionedId()` routes through the seam instead of hand-concatenating, with
  byte-identical output.
- `JsonSchemaGenerator` now maps an `Illuminate\Http\UploadedFile`-typed property to
  `{type: string, format: binary}` — resolved **before** the unknown-class string-degrade
  catch-all. This lets a pure-Data upload DTO project a file field instead of a bare string,
  so the OpenAPI docs for upload endpoints keep `format: binary` when they convert off
  FormRequests. Non-file leaves are unaffected.
