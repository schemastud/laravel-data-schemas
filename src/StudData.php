<?php

namespace Schemastud\DataSchemas;

use Schemastud\DataSchemas\Concerns\DerivesJsonSchema;
use Schemastud\DataSchemas\Contracts\ProvidesJsonSchema;
use Spatie\LaravelData\Data;

/**
 * A `Data` class that knows its own JSON Schema — the short form.
 *
 * `StudBackedData extends StudData` is the whole opt-in; `::jsonSchema()` then answers through the
 * host's configured generator. Nothing here is load-bearing: this class is a two-line composition of
 * {@see ProvidesJsonSchema} and {@see DerivesJsonSchema}, and both are usable without it.
 *
 * That matters, because the `extends` slot is not always free. Several abstract Data bases already
 * exist in this family — `SyncData` twice, `OtioData`, `SecretData`, `Blockdoc\Blocks\Block` (which
 * carries more subclasses than any of the others), `CircuitBlueprint` — and a class under one of
 * those reaches identical behaviour with `use DerivesJsonSchema`. Counting them here goes stale;
 * `StreamingData` and `LineageSnapshotData` were on an earlier list and have since arrived under this
 * class on their own.
 *
 * The trait is the answer for the ones that stay put, and it is CHEAPER than the earlier reading of
 * this paragraph suggested: nearly all the packages holding such a base already require this one,
 * directly or transitively, so `use DerivesJsonSchema` costs an import rather than a dependency
 * negotiation. Where it genuinely does cost a new require — a `rushing/*` open foundation that
 * deliberately depends on nothing here — that is a per-package call and dependency direction wins.
 *
 * Deliberately not `final`: `BeamData` extends it as reserved vocabulary for the tier below, and a host
 * is free to add its own.
 *
 * ⚠️ **There is no `ParticleData` class.** The name was planned but never built (verified 2026-08-29
 * against the family packages, host apps and a booted classmap; `particle-contribution-seam` ticket 12
 * §A4, tickets 10 and 11), so earlier mentions of it alongside `BeamData` were wrong. `BeamData` alone
 * is why this class is not `final`.
 *
 * @api
 */
abstract class StudData extends Data implements ProvidesJsonSchema
{
    use DerivesJsonSchema;
}
