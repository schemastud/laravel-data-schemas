<?php

namespace Schemastud\DataSchemas\Vocabulary;

use Closure;
use Schemastud\DataSchemas\Strategies\SchemaStrategy;

/**
 * Binds one PHP attribute to the keyword(s) it contributes and the closure that stamps them
 * onto a property schema. A single declared binding is consumed by BOTH the emit path (a
 * {@see SchemaStrategy} iterating bindings) and the
 * describe path ({@see KeywordVocabularyDescriber}), so the emitted keywords and the
 * described vocabulary cannot diverge.
 */
class AttributeBinding
{
    /**
     * @param  class-string  $attributeClass  the attribute this binding projects
     * @param  Closure(object, mixed, array<string, mixed>): array<string, mixed>  $emit  stamps this attribute's keyword(s) onto the property schema
     * @param  list<KeywordDescriptor>  $keywords  the keyword(s) this attribute contributes to the vocabulary
     */
    public function __construct(
        public string $attributeClass,
        public Closure $emit,
        public array $keywords,
    ) {}
}
