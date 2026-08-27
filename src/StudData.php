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
 * Deliberately not `final`: `BeamData` and `ParticleData` extend it as reserved vocabulary for the
 * tiers below, and a host is free to add its own.
 */
abstract class StudData extends Data implements ProvidesJsonSchema
{
    use DerivesJsonSchema;
}
