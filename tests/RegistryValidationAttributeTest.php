<?php

namespace Schemastud\DataSchemas\Tests;

use Attribute;
use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\Validator;
use Orchestra\Testbench\TestCase;
use ReflectionClass;
use Rushing\Popcorn\Laravel\PopcornManager;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Rushing\Popcorn\Laravel\Rules\ExistsInRegistry as RegistryRule;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Popcorn\Registries\RegistryKey;
use Schemastud\DataSchemas\Attributes\ExistsInRegistry;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelData\Optional;

class RegistryInputData extends Data
{
    public function __construct(
        #[ExistsInRegistry('test.formats', relative: true)]
        public string $format,
    ) {}
}

class NullableRegistryInputData extends Data
{
    public function __construct(
        #[ExistsInRegistry('test.formats', relative: true)]
        public ?string $format,
    ) {}
}

class OptionalRegistryInputData extends Data
{
    public function __construct(
        #[ExistsInRegistry('test.formats', relative: true)]
        public string|Optional $format,
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class JsonFormat extends ExistsInRegistry
{
    public function __construct()
    {
        parent::__construct('test.formats', relative: true);
    }

    public function constraint(): RegistryRule
    {
        return new RegistryRule($this->prefix, relative: true,
            where: fn ($key): bool => (string) $key === 'test.formats.json');
    }
}

class JsonRegistryInputData extends Data
{
    public function __construct(
        #[JsonFormat]
        public string $format,
    ) {}
}

class RegistryValidationAttributeTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelDataServiceProvider::class, PopcornServiceProvider::class];
    }

    public function test_one_declaration_validates_dto_input_and_projects_its_registry_enum(): void
    {
        $store = new BasicRegistry(new IsRegistry(root: 'test.formats', description: 'Output formats'));
        $store->register('markdown', 'Markdown', by: 'tests');
        $store->register('json', 'JSON', by: 'tests');
        $this->app->make(RegistryIndex::class)->describe($store);

        $this->assertSame('json', RegistryInputData::validateAndCreate(['format' => 'json'])->format);
        $schema = (new JsonSchemaGenerator)->forRequest()->generate(new ReflectionClass(RegistryInputData::class));
        $this->assertSame(['json', 'markdown'], $schema['properties']['format']['enum']);

        $this->expectException(ValidationException::class);
        RegistryInputData::validateAndCreate(['format' => 'yaml']);
    }

    public function test_subsets_and_visibility_reach_validation_and_each_fresh_projection(): void
    {
        $store = new BasicRegistry(new IsRegistry(root: 'test.formats', description: 'Output formats'));
        $store->register('json', 'JSON', by: 'tests');
        $store->register('yaml', 'YAML', by: 'tests');
        $store->register('secret', 'Secret', by: 'tests', ability: 'secret');
        $this->app->make(RegistryIndex::class)->describe($store);
        $generator = new JsonSchemaGenerator;
        $class = new ReflectionClass(RegistryInputData::class);
        $this->assertSame(['json', 'secret', 'yaml'], $generator->generate($class)['properties']['format']['enum']);
        $this->app->make(PopcornManager::class)->authorizeWith(new class implements Authorizer
        {
            public function allows(string $ability, RegistryKey $key): bool
            {
                return false;
            }
        });
        $this->assertSame(['json', 'yaml'], $generator->generate($class)['properties']['format']['enum']);
        $this->assertSame(['json'], $generator->generate(new ReflectionClass(JsonRegistryInputData::class))['properties']['format']['enum']);
        $this->assertSame('json', JsonRegistryInputData::validateAndCreate(['format' => 'json'])->format);
        foreach (['yaml', 'secret', 'missing', 'JSON', 'test.formats.json'] as $value) {
            try {
                JsonRegistryInputData::validateAndCreate(['format' => $value]);
                $this->fail('The DTO accepted '.$value);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('format', $exception->errors());
            }
        }
    }

    public function test_empty_and_nullable_vocabularies_remain_faithful_in_json_schema(): void
    {
        $store = new BasicRegistry(new IsRegistry(root: 'test.formats', description: 'Output formats'));
        $this->app->make(RegistryIndex::class)->describe($store);
        $validator = new Validator;
        $generator = new JsonSchemaGenerator;
        $empty = $generator->generate(new ReflectionClass(RegistryInputData::class));
        $nullable = $generator->generate(new ReflectionClass(NullableRegistryInputData::class));
        $strict = $generator->forLlmStrict()->generate(new ReflectionClass(OptionalRegistryInputData::class));

        $this->assertSame('{}', json_encode($empty['properties']['format']['not']));
        $this->assertFalse($validator->validate((object) ['format' => 'anything'], json_decode(json_encode($empty)))->isValid());
        $this->assertSame([null], $nullable['properties']['format']['enum']);
        $this->assertTrue($validator->validate((object) ['format' => null], json_decode(json_encode($nullable)))->isValid());
        $this->assertNull(NullableRegistryInputData::validateAndCreate(['format' => null])->format);
        $this->assertTrue($validator->validate((object) ['format' => null], json_decode(json_encode($strict)))->isValid());
        $this->assertFalse($validator->validate((object) ['format' => 'anything'], json_decode(json_encode($strict)))->isValid());

        $store->register('json', 'JSON', by: 'tests');
        $strict = $generator->forLlmStrict()->generate(new ReflectionClass(OptionalRegistryInputData::class));
        $this->assertTrue($validator->validate((object) ['format' => null], json_decode(json_encode($strict)))->isValid());
        $this->assertTrue($validator->validate((object) ['format' => 'json'], json_decode(json_encode($strict)))->isValid());
        $this->assertFalse($validator->validate((object) ['format' => 'yaml'], json_decode(json_encode($strict)))->isValid());
    }
}
