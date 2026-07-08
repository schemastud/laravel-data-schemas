<?php

namespace Rushing\LaravelDataSchemas\Tests\Fixtures\Vocabulary;

use Rushing\LaravelDataSchemas\Vocabulary\KeywordDescriptor;
use Rushing\LaravelDataSchemas\Vocabulary\KeywordVocabularyDescriber;
use Rushing\LaravelDataSchemas\Vocabulary\ValueSource;

/**
 * A concrete describer over one keyword of each ValueSource — the mechanism's test subject.
 */
class SampleDescriber extends KeywordVocabularyDescriber
{
    protected function descriptors(): iterable
    {
        return [
            new KeywordDescriptor('flavor', ValueSource::Enum, 'an enum', sourceClass: SampleFlavor::class, tsType: 'SampleFlavor'),
            new KeywordDescriptor('choice', ValueSource::Union, 'a union', sourceClass: SampleUnionSource::class, sourceMethod: 'keyword'),
            new KeywordDescriptor('toggle', ValueSource::Boolean, 'a bool'),
            new KeywordDescriptor('depth', ValueSource::Integer, 'an int'),
            new KeywordDescriptor('note', ValueSource::Text, 'a string'),
            new KeywordDescriptor('policy', ValueSource::Object_, 'an object', sourceClass: SamplePolicySource::class, tsType: 'SamplePolicy'),
        ];
    }

    protected function keywordString(KeywordDescriptor $keyword): string
    {
        return 'x-sample-'.$keyword->accessor;
    }

    protected function title(): string
    {
        return 'SampleKeywords';
    }

    protected function description(): string
    {
        return 'sample dialect';
    }
}
