<?php

namespace Schemastud\DataSchemas\Vocabulary;

/**
 * One extension keyword, declared once by its owner. It names the OWNER'S handle for the
 * keyword (the `accessor` — resolved to the concrete, possibly prefixed, keyword string by
 * {@see KeywordVocabularyDescriber::keywordString()}) and a *reference* to where its value
 * domain is reflected from — an enum class, a method return type, a constructor — never a
 * literal value schema. Both an emit path (via an {@see AttributeBinding}) and the describe
 * path ({@see KeywordVocabularyDescriber}) read these, so the two can never drift.
 */
class KeywordDescriptor
{
    /**
     * @param  string  $accessor  the owner's handle for this keyword (resolved to the keyword string by the describer)
     * @param  ValueSource  $source  how the value domain is reflected
     * @param  string  $description  one line, owned here by the keyword's owner
     * @param  class-string|null  $sourceClass  the enum / ctor / method-owning class the value is reflected from
     * @param  string|null  $sourceMethod  the method whose return type is reflected (ValueSource::Union)
     * @param  string|null  $tsType  a named TypeScript type to emit for this value (enums, objects)
     */
    public function __construct(
        public string $accessor,
        public ValueSource $source,
        public string $description,
        public ?string $sourceClass = null,
        public ?string $sourceMethod = null,
        public ?string $tsType = null,
    ) {}
}
