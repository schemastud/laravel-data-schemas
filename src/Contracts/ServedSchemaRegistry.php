<?php

namespace Schemastud\DataSchemas\Contracts;

/**
 * The registry that answers the PUBLIC schema door — the subset of a host's artifacts it is willing
 * to serve at its declared `data-schemas.base_uri` (beam-facade ticket 82).
 *
 * It declares no members of its own, and that is deliberate: its entire job is to be a DIFFERENT
 * CONTAINER KEY from {@see SchemaRegistry}. The general binding is routinely a composite — at a beam
 * host, `BeamSchemaRegistry` over `['fleet','db','file']`, whose tiers are opaque behind `get()` —
 * and one of those tiers resolves against the ACTIVE TENANT CONNECTION. A door resolving the general
 * contract could not tell which tier answered, so the line below would be enforced by nothing.
 *
 * THE LINE: this door serves a host's own COMMITTED artifacts, never a tenant's runtime-registered
 * ones. That is not a preference invented here — it is the division of labour
 * {@see \Splicewire\Beam\Schema\DatabaseSchemaRegistry} already states in its own docblock (the
 * filesystem registry "holds a host's COMMITTED code schemas"; the db registry "resolves schemas a
 * downstream tenant registered at RUNTIME"). It is structural rather than a policy knob because
 * ticket 64 found tenant `$id`s are PAYLOAD-SUPPLIED with nothing validating that a tenant owns the
 * authority it claims: serving them from this host's origin would mint exactly the unowned-authority
 * claim 64 spent itself eliminating.
 *
 * Filtering after the fact was rejected for the same reason `ignore` means indistinguishable on the
 * capture ledger — asking the tenant tier for the artifact before deciding it should not have leaks
 * an existence oracle through timing and through every error path.
 *
 * Serving tenant schemas later is an extension of THIS door, not a second one: the reconstruction is
 * request-derived, so a tenant domain already names the tenant's authority. What it waits on is an
 * ownership check on tenant registration, not a registry swap.
 */
interface ServedSchemaRegistry extends SchemaRegistry {}
