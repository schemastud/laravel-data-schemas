> You are in **schemastud/laravel-data-schemas** — generate JSON Schemas from Laravel Data objects with validation attributes.

Converts your Spatie Laravel Data objects into JSON Schema files, preserving validation rules,
descriptions, and examples. Useful for AI-powered applications, API documentation, and frontend
validation.

## A declared Data class is the input, always

This package projects a Data class into a JSON Schema. It never infers a shape from an array, a
docblock, or a runtime sample — if a shape is not declared, there is nothing here to generate from.

Consumers routinely build a stricter rule on top of that. A CMS runtime, for instance, may require
that *every* boundary-crossing shape — HTTP request and response bodies, tool inputs and outputs,
event payloads — be a declared Data class rather than an inline array, so one declaration drives the
schema, the API docs, and the generated client types together. Enforcing that is the consumer's job.

The obligation here is narrower and absolute: whatever Data class arrives projects **faithfully** —
validation rules, descriptions, examples, nullability and all. A consumer's doctrine is only worth
having if the projection under it is exact.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
