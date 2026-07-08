<?php

namespace Rushing\LaravelDataSchemas\Vocabulary;

/**
 * How a keyword's value domain is *reflected* from its owner — never a hand-authored
 * value schema. {@see KeywordVocabularyDescriber} resolves each of these into a
 * JSON-Schema fragment + a TypeScript type by reflecting the referenced enum / method /
 * constructor, so adding an enum case (or a ctor param) flows into the described
 * vocabulary with no describer edit.
 */
enum ValueSource
{
    /** A backed enum: value domain is its live `::cases()`. */
    case Enum;

    /** A method's declared return type, projected as a union (e.g. `string|bool`). */
    case Union;

    /** A plain boolean flag. */
    case Boolean;

    /** A plain integer. */
    case Integer;

    /** A plain string (a note, a dispatch handle). */
    case Text;

    /** An object whose shape is reflected from a class constructor. */
    case Object_;
}
