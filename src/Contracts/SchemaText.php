<?php

namespace Schemastud\DataSchemas\Contracts;

use BackedEnum;
use ReflectionClass;

/**
 * The display text the generator writes outside its property-strategy loop: a class's title and description, an enum's
 * title and its case labels (`enumNames`). The default implementation, {@see \Schemastud\DataSchemas\Text\IdentitySchemaText},
 * returns the declared text unchanged, so with nothing bound the generator's output is byte-identical.
 *
 * A host or a package above this one binds its own to translate that text: splicewire/laravel-beam binds one that walks
 * its localization ladder (localization ticket 09). Property titles and descriptions are not here: they are set before
 * the property strategies run, so a {@see \Schemastud\DataSchemas\Strategies\SchemaStrategy} rewrites them.
 *
 * {@see enumCaseLabel()} is also called outside schema generation (a filter's enum options read it), so an
 * implementation must not assume a generator is running.
 */
interface SchemaText
{
    /**
     * A class's title. `$declared` is its own `#[Title]`, or null; return null to keep the generator's own fallback.
     *
     * @param  ReflectionClass<object>  $class
     */
    public function classTitle(ReflectionClass $class, ?string $declared): ?string;

    /**
     * A class's description: its own `#[Description]`, or null.
     *
     * @param  ReflectionClass<object>  $class
     */
    public function classDescription(ReflectionClass $class, ?string $declared): ?string;

    /**
     * An enum's title: its own `#[Title]`, or null.
     *
     * @param  class-string<BackedEnum>  $enumClass
     */
    public function enumTitle(string $enumClass, ?string $declared): ?string;

    /** One enum case's label. `$declared` is what the enum itself says (its `label()`, or the caller's fallback). */
    public function enumCaseLabel(BackedEnum $case, string $declared): string;
}
