<?php

namespace Schemastud\DataSchemas\Sources;

use ReflectionClass;

/**
 * One way of knowing which classes this host projects schemas from.
 *
 * The entry type of {@see SchemaProjectionRegistry}, and deliberately the ONLY thing that registry
 * holds. The two obvious alternatives both foreclose the contribution the seam exists to accept:
 *
 * - **Paths** are one source's private vocabulary. {@see PathScanSource} has them; a source reading a
 *   particle registry, an explicit manifest, or a per-tenant table has none. A registry of paths is a
 *   registry only the path scanner can join.
 * - **Class-strings** would have to be enumerated at REGISTRATION time — in a provider's `boot()` —
 *   which is the boot-order trap. A source registered before the registry it reads is populated would
 *   contribute an empty list, silently, and the registry would have recorded load order as truth. So
 *   the contract is a question, asked at READ time, and a source that has nothing to say yet says so
 *   then rather than forever.
 *
 * ONE method, on purpose. A source that also wants to say how it found something is describing itself,
 * and that is what the registry key and the `by` registrant already carry.
 */
interface SchemaSource
{
    /**
     * The classes this source contributes, right now.
     *
     * Reflections rather than class-strings because every downstream consumer in this package
     * ({@see \Schemastud\DataSchemas\Actions\GenerateSchemasAction}, {@see \Schemastud\DataSchemas\Generators\Generator})
     * takes a `ReflectionClass`, and a source that already reflected to decide should not throw that away.
     *
     * @return list<ReflectionClass>
     */
    public function classes(): array;
}
