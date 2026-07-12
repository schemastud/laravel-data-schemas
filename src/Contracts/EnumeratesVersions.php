<?php

namespace Schemastud\DataSchemas\Contracts;

/**
 * A registry capability: enumerate the known integer versions registered under a
 * schema stem (the `<base>/<name>` portion of an absolute versioned `$id`, sans
 * the trailing version segment).
 *
 * This is an ownership-AGNOSTIC enumeration only — it answers "which version
 * integers exist for this stem?" and nothing more. All version-selection policy
 * (what "latest" means, system-vs-tenant precedence) lives in the host above the
 * registry, not here; the package stays exact-`$id` keyed and policy-free.
 *
 * An unknown stem returns an empty array — never an exception — so "does any
 * version of this stem exist?" is a cheap, total query.
 */
interface EnumeratesVersions
{
    /**
     * The version integers registered under `$stem`, ascending and de-duplicated.
     * An unknown stem (or one whose ids carry no integer version) returns `[]`.
     *
     * @return array<int, int>
     */
    public function versionsFor(string $stem): array;
}
