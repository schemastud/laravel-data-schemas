<?php

namespace Schemastud\DataSchemas\Overlay\Lens;

// The tier axis of a registered lens — and the reason the registry can make the
// applied lenses *visible* without *promoting* them.
//
// ADR-0155 (with ADR-0092's vendor seam, see this directory's README) draws a
// deliberate line between two kinds of declared lens, and a flat registry would
// erase it the moment it listed them side by side:
//
//   - **host-applied** — a satellite's own carrier convenience. Declared by the
//     app, applied at app call sites, and true only of that app's data. Nothing
//     downstream may treat it as the meaning of the canonical: another host
//     mapping the same `@id` differently is not in conflict with it.
//   - **engine-authoritative** — the binding the engine itself stands behind
//     (the `SpineBinding` case: the music-profile ↔ OTIO projection Tower's
//     `MusicSpine` owns). It is the definition of the projection for every host,
//     so a host disagreeing with it is wrong rather than different.
//
// Registration is discoverability, not endorsement. A host-applied lens joins
// the registry so that a search for lens usage FINDS it — the failure this
// registry exists to fix was an exhaustive search concluding there were zero
// lens consumers while three were in production behind a private facade — and
// the tier is what keeps that visibility from reading as fleet blessing.
enum LensTier: string
{
    case HostApplied = 'host-applied';

    case EngineAuthoritative = 'engine-authoritative';

    // A one-line account of what registering at this tier does and does not claim.
    public function blurb(): string
    {
        return match ($this) {
            self::HostApplied => 'Declared and applied by one host; visible fleet-wide, authoritative nowhere. Another host may map the same @id differently.',
            self::EngineAuthoritative => 'The engine\'s own binding for this @id — the projection every host is measured against.',
        };
    }
}
