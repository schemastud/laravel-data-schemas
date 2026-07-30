<?php

namespace Schemastud\DataSchemas\Overlay\Lens;

// The honesty tag on a directed lens. `lossless-eligible` claims the pair obeys
// the well-behavedness laws (GetPut/PutGet) and may therefore be presented as
// two projections of one canonical; `lossy` admits the return trip drops state.
// A lens is only *eligible* here — the resolver (a later slice) proves or
// downgrades the claim by actually exercising the laws. Nothing is ever silently
// trusted: an eligible lens that fails the laws is downgraded to `lossy`.
enum Fidelity: string
{
    case LosslessEligible = 'lossless-eligible';

    case Lossy = 'lossy';
}
