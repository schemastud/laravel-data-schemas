<?php

namespace Schemastud\DataSchemas\Attributes;

use Attribute;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use Spatie\LaravelData\Attributes\PropertyForMorph;
use Spatie\LaravelData\Contracts\PropertyMorphableData;
use Spatie\LaravelData\Data;

/** A closed, declared morph vocabulary. Registry-backed morphs are not enumerated. */
#[Attribute(Attribute::TARGET_CLASS)]
final class MorphUnion
{
    /** @param array<string, class-string<Data>> $arms */
    public function __construct(public string $discriminator, public array $arms) {}

    public static function forClass(ReflectionClass $base): ?self
    {
        $attribute = $base->getAttributes(self::class)[0] ?? null;
        if ($attribute === null) {
            return null;
        }
        $union = $attribute->newInstance();
        if (! $base->isSubclassOf(Data::class) || ! $base->implementsInterface(PropertyMorphableData::class)) {
            throw new InvalidArgumentException('MorphUnion requires a PropertyMorphableData base.');
        }
        if ($union->discriminator === '' || $union->arms === []) {
            throw new InvalidArgumentException('MorphUnion requires a discriminator and non-empty arms.');
        }
        $baseClass = $base->getName();
        foreach ($union->arms as $tag => $arm) {
            if (! is_string($tag) || $tag === '' || ! is_string($arm) || ! is_subclass_of($arm, $base->getName())) {
                throw new InvalidArgumentException('MorphUnion arms require string tags and subclasses of the base.');
            }
            $class = new ReflectionClass($arm);
            if ($class->isAbstract() || ! $class->hasProperty($union->discriminator)) {
                throw new InvalidArgumentException('MorphUnion arms must be concrete and declare the discriminator.');
            }
            $property = $class->getProperty($union->discriminator);
            $type = $property->getType();
            if (! $property->isPublic() || $property->isStatic() || ! $type instanceof ReflectionNamedType || $type->getName() !== 'string' || $type->allowsNull()) {
                throw new InvalidArgumentException('MorphUnion discriminator must be a public non-null string property.');
            }
        }
        if (! $base->hasProperty($union->discriminator) || $base->getProperty($union->discriminator)->getAttributes(PropertyForMorph::class) === []) {
            throw new InvalidArgumentException('MorphUnion discriminator must carry #[PropertyForMorph] on the base for runtime hydration.');
        }
        foreach ($union->arms as $tag => $arm) {
            if ($baseClass::morph([$union->discriminator => $tag]) !== $arm) {
                throw new InvalidArgumentException('MorphUnion arm must match the base runtime morph for its discriminator tag.');
            }
        }

        return $union;
    }
}
