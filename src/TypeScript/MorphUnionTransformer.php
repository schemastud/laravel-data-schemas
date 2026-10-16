<?php

namespace Schemastud\DataSchemas\TypeScript;

use ReflectionClass;
use Schemastud\DataSchemas\Attributes\MorphUnion;
use Spatie\TypeScriptTransformer\Data\TransformationContext;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\References\PhpClassReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\Transformed\Untransformable;
use Spatie\TypeScriptTransformer\Transformers\Transformer;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptAlias;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptIntersection;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptLiteral;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptObject;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptProperty;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptReference;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptUnion;

/** Register before the ordinary Data/class transformer; unannotated classes fall through. */
final class MorphUnionTransformer implements Transformer
{
    public function transform(PhpClassNode $phpClassNode, TransformationContext $context): Transformed|Untransformable
    {
        if ($phpClassNode->getAttributes(MorphUnion::class) === []) {
            return Untransformable::create();
        }
        $union = MorphUnion::forClass(new ReflectionClass($phpClassNode->getName()));
        $arms = [];
        foreach ($union->arms as $tag => $class) {
            $arms[] = new TypeScriptIntersection([
                TypeScriptReference::referencingPhpClass($class),
                new TypeScriptObject([new TypeScriptProperty($union->discriminator, new TypeScriptLiteral($tag))]),
            ]);
        }

        return new Transformed(
            new TypeScriptAlias($context->name, new TypeScriptUnion($arms)),
            new PhpClassReference($phpClassNode),
            $context->nameSpaceSegments,
        );
    }
}
