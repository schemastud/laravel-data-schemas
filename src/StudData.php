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
 * That matters, because the `extends` slot is not always free. Six abstract Data bases already exist
 * in this family (`SyncData` twice, `StreamingData`, `LineageSnapshotData`, `OtioData`, `SecretData`),
 * and a class under one of those reaches identical behaviour with `use DerivesJsonSchema`. Those six
 * MAY be rebased onto this class, but that is a per-package dependency decision rather than a
 * mechanical one — three of the five packages holding them do not currently require this package at
 * all, and two of those are `rushing/*` open foundations.
 *
 * Deliberately not `final`: `BeamData` extends it as reserved vocabulary for the tier below, and a host
 * is free to add its own.
 *
 * ⚠️ This sentence used to name `ParticleData` alongside `BeamData`, and **`ParticleData` does not
 * exist** — verified 2026-08-29 by three differently-shaped instruments: a filesystem read over the
 * family package roots and every host's `app/` finds exactly one occurrence estate-wide, which is this
 * sentence; `git log -S` names only the commit that wrote it; and a booted `class_exists()` plus a
 * composer classmap scan at the flagship resolve nothing, with `BeamData` as a working control. It was
 * `particle-contribution-seam` ticket 12 §A4's planned name, cited as real by tickets 10 and 11 during
 * planning and never built. `not final` is still right — `BeamData` alone earns it — so the class is
 * unchanged and only the claim is.
 */
abstract class StudData extends Data implements ProvidesJsonSchema
{
    use DerivesJsonSchema;
}
