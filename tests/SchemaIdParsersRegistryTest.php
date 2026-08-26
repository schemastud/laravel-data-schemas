<?php

namespace Schemastud\DataSchemas\Tests;

use Orchestra\Testbench\TestCase;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryIndex;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Ids\RelativeSchemaIdParser;
use Schemastud\DataSchemas\Ids\SchemaIdParsersRegistry;
use Schemastud\DataSchemas\Ids\SchemaIdResolver;
use Schemastud\DataSchemas\Ids\UnresolvableRelativeSchemaId;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Tests\Fixtures\PrefixingSchemaIdParser;

/**
 * The container half of beam-facade ticket 140: the ref-grammar seam is a declared, indexed registry,
 * and the resolver reads the host's real `base_uri` off config.
 *
 * This is the shape ticket 141 will use to register `splicewire/tower`'s grammar from tower's own
 * provider — so if these pass, 141 needs to touch nothing in this package.
 */
class SchemaIdParsersRegistryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            // laravel-popcorn binds RegistryIndex as a SINGLETON. Without it the index is
            // auto-resolvable but UNSHARED, so describe() lands on a throwaway — see the same note on
            // SchemaStrategiesRegistryTest.
            PopcornServiceProvider::class,
            LaravelDataSchemasServiceProvider::class,
        ];
    }

    public function test_it_is_bound_as_a_singleton_by_this_package(): void
    {
        $this->assertSame(app(SchemaIdParsersRegistry::class), app(SchemaIdParsersRegistry::class));
    }

    public function test_it_declares_itself_so_the_gate_can_read_it(): void
    {
        $declaration = IsRegistry::of(SchemaIdParsersRegistry::class);

        $this->assertNotNull($declaration);
        $this->assertSame('schemas.id-parsers', $declaration->root);
        $this->assertSame([RegistryArity::PickOne], $declaration->arity);
    }

    public function test_it_is_described_into_the_shared_index(): void
    {
        $keys = array_map(strval(...), app(RegistryIndex::class)->keys());

        $this->assertContains('schemas.id-parsers', $keys);
    }

    public function test_it_ships_empty_so_the_default_grammar_cannot_be_shadowed(): void
    {
        // The floor is not an entry. If it ever appears here, a registrant appended after it can
        // never fire — it claims every ref.
        $this->assertSame([], config('data-schemas.id_parsers'));
        $this->assertSame([], app(SchemaIdParsersRegistry::class)->keys());
    }

    public function test_a_package_registers_its_grammar_and_the_resolver_uses_it(): void
    {
        // Exactly what tower's provider will do in ticket 141.
        app(SchemaIdParsersRegistry::class)->register(
            'prefixing-schema-id-parser',
            PrefixingSchemaIdParser::class,
        );

        config()->set('data-schemas.base_uri', 'https://app.splicewire.com/schemas');

        // Shipping EMPTY has a consequence worth pinning rather than discovering: ConfigRegistry
        // treats an empty array as a MAP (a map is the general case, a list the special one), so the
        // first registrant lands keyed rather than appended. The strategies registry never shows this
        // because it ships three entries. Both shapes are read the same way here — the resolver takes
        // `array_values()` — so a package may equally append to the config list directly, which is
        // what the estate's five strategy registrants already do.
        $this->assertSame(
            ['prefixing-schema-id-parser' => PrefixingSchemaIdParser::class],
            config('data-schemas.id_parsers'),
        );
        $this->assertSame(
            'https://app.splicewire.com/schemas/content-schema/demo/guest-intake',
            app(SchemaIdResolver::class)->resolveId('demo/guest-intake'),
        );
    }

    public function test_a_package_may_equally_append_to_the_config_list_directly(): void
    {
        // The estate's habitual spelling — read, append under an `in_array` guard, write back.
        config()->set('data-schemas.base_uri', 'https://app.splicewire.com/schemas');
        config()->set('data-schemas.id_parsers', [PrefixingSchemaIdParser::class]);

        $this->assertSame(
            'https://app.splicewire.com/schemas/content-schema/demo/guest-intake',
            app(SchemaIdResolver::class)->resolveId('demo/guest-intake'),
        );
    }

    public function test_the_resolver_reads_the_hosts_declared_authority(): void
    {
        config()->set('data-schemas.base_uri', 'https://tower.test/schemas');

        $this->assertSame(
            'https://tower.test/schemas/content-schema/x/1',
            app(SchemaIdResolver::class)->resolveId('content-schema/x/1'),
        );
    }

    public function test_the_resolver_is_not_a_singleton_so_a_changed_authority_is_honoured(): void
    {
        config()->set('data-schemas.base_uri', 'https://a.test/schemas');
        $this->assertSame('https://a.test/schemas/x/1', app(SchemaIdResolver::class)->resolveId('x/1'));

        config()->set('data-schemas.base_uri', 'https://b.test/schemas');
        $this->assertSame('https://b.test/schemas/x/1', app(SchemaIdResolver::class)->resolveId('x/1'));
    }

    public function test_the_shipped_config_declares_no_authority(): void
    {
        // `mergeConfigFrom` means every root in the estate inherits this. A default here would be one
        // vendor's domain stamped onto every other vendor's schemas.
        $this->assertNull(config('data-schemas.base_uri'));

        $this->expectException(UnresolvableRelativeSchemaId::class);
        app(SchemaIdResolver::class)->resolveId('content-schema/x/1');
    }

    /**
     * Ticket 140's first design call, asserted rather than only argued: resolution happens on WRITE,
     * so nothing relative reaches storage and every registry key stays absolute.
     *
     * That is what keeps `SchemaDocumentController`'s contract — *"THE `$id` IS THE REQUEST URL"* —
     * untouched, and what keeps a `LIKE <base>/<namespace>/%` scope written against an absolute
     * prefix. Under resolve-on-read all three would have had to move together.
     */
    public function test_a_relative_ref_is_expanded_before_it_reaches_the_registry(): void
    {
        config()->set('data-schemas.base_uri', 'https://app.splicewire.com/schemas');

        $dir = sys_get_temp_dir().'/lds-ids-'.getmypid().'-'.uniqid();
        config()->set('data-schemas.registry_directory', $dir);

        $id = app(SchemaIdResolver::class)->resolveId('content-schema/demo/guest-intake/1');
        $registry = app(SchemaRegistry::class);

        $registry->register(['$id' => $id, 'type' => 'object']);

        try {
            $this->assertSame(['https://app.splicewire.com/schemas/content-schema/demo/guest-intake/1'], $registry->ids());
            $this->assertFalse($registry->has('content-schema/demo/guest-intake/1'));

            // And the absolute key IS the URL the door would reconstruct for that request.
            $this->assertTrue($registry->has($id));
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function test_an_unclaimed_ref_falls_to_the_floor_even_with_registrants_present(): void
    {
        config()->set('data-schemas.base_uri', 'https://app.splicewire.com/schemas');
        config()->set('data-schemas.id_parsers', [PrefixingSchemaIdParser::class]);

        $this->assertInstanceOf(
            RelativeSchemaIdParser::class,
            app(SchemaIdResolver::class)->parserFor('content-schema/demo/guest-intake/1'),
        );
    }
}
