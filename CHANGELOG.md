# Changelog

All notable changes to `schemastud/laravel-data-schemas` are documented here.

## Unreleased

### Added
- `JsonSchemaGenerator` now maps an `Illuminate\Http\UploadedFile`-typed property to
  `{type: string, format: binary}` — resolved **before** the unknown-class string-degrade
  catch-all. This lets a pure-Data upload DTO project a file field instead of a bare string,
  so the OpenAPI docs for upload endpoints keep `format: binary` when they convert off
  FormRequests. Non-file leaves are unaffected.
