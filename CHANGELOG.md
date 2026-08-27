# Changelog

All notable changes to `schemastud/laravel-data-schemas` are documented here.

## Unreleased

### Changed
- **The schema collection family splits on provenance, and the writer moved onto a disk.**
  `GeneratedSchema` no longer carries an `outputPath` — most projections of this generator have no
  path (the in-memory registry, `ServedSchemaChain`, the OpenAPI leg), so a destination on the base
  type was a disk flavour every caller had to ignore. `WrittenSchema` adds it, `FileSchemaCollection`
  holds those, and `GenerateSchemasAction` returns one. `SchemaCollection` gains a deliberately small
  interrogation surface — `fingerprints()`, the **pure** `diffAgainst(array $onDisk)` (the shape
  beam's `schema.projection-drift` audit consumes), `registerInto(SchemaRegistry)` — plus
  `pathCollisions()` on the file collection, which reports the last-write-wins overwrite
  `path_structure: 'flat'` has always been able to produce in silence. The collection is **not**
  path-keyed, because a path-keyed collection drops the very duplicate `pathCollisions()` exists to
  find. Both collections annotate their generics and mirror Eloquent's `map()`/`mapWithKeys()`
  downgrade so the annotation stays true. Filesystem reads live in one class, `SchemaFileReader`.
  `addSchema()` is **gone** — it was `push()` with a type hint.
- **`Writers\JsonSchemaWriter` → `Writers\SchemaFileWriter`, writing through `Storage`.** The
  contract keeps the idea-name; the implementation takes the strategy-name, as with
  `SchemaRegistry` ← `FilesystemSchemaRegistry`. The package now defines a **`data-schemas` disk**
  (a `local` driver rooted at `output_directory`, defined only if the host has not), so
  `Storage::fake('data-schemas')` is the testing story — the old absolute-path `File::put` was
  unfakeable, which is why this whole path had no tests. `JsonSchemaWriter` survives as a
  deprecated subclass: `writer` is a published config key and hosts carry the old name.
  `Writer::write()` now takes a `FileSchemaCollection`; a host implementation typed on
  `SchemaCollection` still satisfies it (parameter widening is legal).

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
