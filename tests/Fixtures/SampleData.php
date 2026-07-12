<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Example;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Uuid;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Lazy;
use Spatie\LaravelData\Optional;

#[Description('A representative resource exercising every mapping rule.')]
class SampleData extends Data
{
    public function __construct(
        #[Max(255)]
        #[Description('Human-readable title.')]
        public string $title,

        #[Email]
        public string $email,

        #[Uuid]
        #[Example('11111111-1111-1111-1111-111111111111')]
        public string $uuid,

        public ?string $bio,

        public string|Optional $nickname,

        public Lazy|UserData|null $user,

        public StatusEnum $status,

        /** @var UserData[] */
        #[DataCollectionOf(UserData::class)]
        public array $collaborators,
    ) {}
}
