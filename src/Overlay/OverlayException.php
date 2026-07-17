<?php

namespace Schemastud\DataSchemas\Overlay;

use InvalidArgumentException;

// Thrown when an overlay document/action is malformed, or when an op cannot be
// applied against the base (e.g. an override that matches nothing and is not a
// concrete create-absent target).
class OverlayException extends InvalidArgumentException {}
