<?php

namespace Schemastud\DataSchemas\Ids;

/**
 * The one thing the three "this host has no authority to mint against" failures have in common.
 *
 * The regime grew its guards one seam at a time and they carry different payloads by necessity —
 * {@see \Schemastud\DataSchemas\Generators\MissingSchemaBaseUri} and
 * {@see \Schemastud\DataSchemas\Generators\NonAbsoluteSchemaBaseUri} name the CLASS whose `$id` was
 * about to be frozen, because at that seam a class is what the author can act on;
 * {@see UnresolvableRelativeSchemaId} names the REF, because a ref arrives from data and has no class
 * behind it. Beam-facade ticket 140 added the third and this interface is what keeps them one rule
 * rather than three: a caller that wants to say "this host cannot resolve schema identity" catches
 * the interface and gets all of them, including whichever one is added next.
 *
 * It is deliberately empty. There is no behaviour common to the three beyond being that answer, and
 * inventing some — a `baseUri()` accessor, say — would force the ref-side failure to pretend it knows
 * something it does not.
 */
interface UndeclaredSchemaAuthority {}
