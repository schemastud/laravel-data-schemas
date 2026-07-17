<?php

namespace Schemastud\DataSchemas\Overlay;

// Host adapter: maps ambient state (request / tenant / locale / vertical …) to
// an **ordered list of active overlay keys**. Order is meaning — the returned
// order is the fold order, so specificity policy ("does tenant beat vertical?")
// lives here in the host's ordering, never as auto-specificity in the core.
//
// Keys are opaque flat literal strings; dimensioned keys (`tenant:acme`,
// `locale:es-MX`) are a colon-namespace convention, not a type system. The base
// ships a trivial StaticOverlayResolver; the concrete tenant/locale resolver is
// host-side (ADR-0046).
interface DataOverlayResolver
{
    /**
     * @return string[] active overlay keys, in fold order
     */
    public function resolve(mixed $context = null): array;
}
