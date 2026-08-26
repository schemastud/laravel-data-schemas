<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Generators\MissingSchemaBaseUri;
use Schemastud\DataSchemas\Generators\NonAbsoluteSchemaBaseUri;
use Schemastud\DataSchemas\Ids\RelativeSchemaIdParser;
use Schemastud\DataSchemas\Ids\SchemaIdResolver;
use Schemastud\DataSchemas\Ids\UndeclaredSchemaAuthority;
use Schemastud\DataSchemas\Ids\UnresolvableRelativeSchemaId;
use Schemastud\DataSchemas\Tests\Fixtures\PrefixingSchemaIdParser;

/**
 * beam-facade ticket 140 — a relative schema id resolves against the host's DECLARED authority, so a
 * package stops having to invent one in order to have a grammar.
 *
 * The three design calls the ticket owned are each asserted here rather than only argued in a
 * docblock: resolution happens on WRITE (nothing stores a relative key), the tri-state gets three
 * DIFFERENT answers, and the parser seam is exercised through a registrant that is not the built-in.
 */
class RelativeSchemaIdTest extends TestCase
{
    private const AUTHORITY = 'https://app.splicewire.com/schemas';

    private function resolver(string|bool|null $baseUri, array $parsers = []): SchemaIdResolver
    {
        return new SchemaIdResolver($baseUri, $parsers);
    }

    // ---------------------------------------------------------------- the grammar

    public function test_a_relative_id_resolves_against_the_declared_authority(): void
    {
        $this->assertSame(
            'https://app.splicewire.com/schemas/content-schema/food-safety/kitchen-log/1',
            $this->resolver(self::AUTHORITY)->resolveId('content-schema/food-safety/kitchen-log/1'),
        );
    }

    public function test_the_resolved_id_round_trips_as_a_stem_and_a_version(): void
    {
        // The parsing half was already total upstream (NamespaceUri splits on the last `/` with a
        // ctype_digit tail test); what 140 adds is the completion. Assert both halves survive it.
        $id = $this->resolver(self::AUTHORITY)->resolve('content-schema/food-safety/kitchen-log/1');

        $this->assertSame('https://app.splicewire.com/schemas/content-schema/food-safety/kitchen-log', $id->stem());
        $this->assertSame(1, $id->version());
        $this->assertTrue($id->isPinned());
    }

    public function test_an_unversioned_relative_ref_resolves_and_stays_unpinned(): void
    {
        $id = $this->resolver(self::AUTHORITY)->resolve('content-schema/food-safety/kitchen-log');

        $this->assertSame('https://app.splicewire.com/schemas/content-schema/food-safety/kitchen-log', (string) $id);
        $this->assertTrue($id->isUnpinned());
    }

    public function test_an_absolute_ref_is_returned_untouched(): void
    {
        // Already complete — it needs no authority, so it must not acquire one.
        $absolute = 'https://audiostud.io/schemas/commerce/money/2';

        $this->assertSame($absolute, $this->resolver(self::AUTHORITY)->resolveId($absolute));
    }

    #[DataProvider('joinShapes')]
    public function test_the_join_never_doubles_or_drops_a_separator(string $base, string $ref, string $expected): void
    {
        $this->assertSame($expected, $this->resolver($base)->resolveId($ref));
    }

    public static function joinShapes(): array
    {
        return [
            'trailing slash on the authority' => ['https://x.test/schemas/', 'a/b/1', 'https://x.test/schemas/a/b/1'],
            'leading slash on the ref' => ['https://x.test/schemas', '/a/b/1', 'https://x.test/schemas/a/b/1'],
            'both' => ['https://x.test/schemas/', '/a/b/1', 'https://x.test/schemas/a/b/1'],
            'neither' => ['https://x.test/schemas', 'a/b/1', 'https://x.test/schemas/a/b/1'],
            'a path-less authority' => ['https://schemas.x.test', 'a/b/1', 'https://schemas.x.test/a/b/1'],
            'whitespace around the ref' => ['https://x.test/schemas', '  a/b/1 ', 'https://x.test/schemas/a/b/1'],
        ];
    }

    public function test_the_authority_is_genuinely_host_supplied(): void
    {
        // The single best test that nothing was re-hardcoded: the same ref under two declared
        // authorities must produce two different ids. `~/Herd/tower` declaring
        // SCHEMA_BASE_URI=https://tower.test/schemas is the live version of this assertion.
        $ref = 'content-schema/demo/guest-intake/1';

        $this->assertSame(
            'https://app.splicewire.com/schemas/content-schema/demo/guest-intake/1',
            $this->resolver(self::AUTHORITY)->resolveId($ref),
        );
        $this->assertSame(
            'https://tower.test/schemas/content-schema/demo/guest-intake/1',
            $this->resolver('https://tower.test/schemas')->resolveId($ref),
        );
    }

    // ---------------------------------------------------------------- the tri-state, all three

    public function test_an_unset_authority_throws_rather_than_minting_a_relative_id(): void
    {
        // Ticket 112's defect, reached from the ref side. There is nothing to resolve against and
        // the host has not decided what there should be, so this must never fall back.
        $this->expectException(UnresolvableRelativeSchemaId::class);

        $this->resolver(null)->resolveId('content-schema/x/1');
    }

    public function test_an_authority_that_names_no_origin_throws_too(): void
    {
        // `/schemas` is a decision nobody finished making — 112 again, and the message says so.
        try {
            $this->resolver('/schemas')->resolveId('content-schema/x/1');
            $this->fail('Expected UnresolvableRelativeSchemaId.');
        } catch (UnresolvableRelativeSchemaId $e) {
            $this->assertStringContainsString('names no origin', $e->getMessage());
            $this->assertSame('/schemas', $e->baseUri);
            $this->assertSame('content-schema/x/1', $e->ref);
        }
    }

    public function test_an_empty_authority_is_not_a_declaration(): void
    {
        $this->expectException(UnresolvableRelativeSchemaId::class);

        $this->resolver('   ')->resolveId('content-schema/x/1');
    }

    public function test_an_opted_out_host_resolves_a_relative_ref_to_itself(): void
    {
        // The third answer, and it is deliberately neither of the other two. `false` means the host
        // has DECIDED it mints no versioned identity — `versionedId()` returns null and
        // SchemaIdentity classes keep a bare short-name `$id`, and no door is mounted. A relative ref
        // there is already the whole identity: nothing is missing, so nothing is thrown.
        $id = $this->resolver(false)->resolve('content-schema/x/1');

        $this->assertSame('content-schema/x/1', (string) $id);
        $this->assertSame('content-schema/x', $id->stem());
        $this->assertSame(1, $id->version());
    }

    public function test_the_three_states_give_three_different_answers(): void
    {
        // Stated as one assertion because the ticket asked for it as one question: unset is
        // UNDECIDED, `false` is DECIDED-THAT-THERE-IS-NONE, and an origin resolves. Collapsing any
        // two of these is the regression this guards.
        $ref = 'content-schema/x/1';

        $this->assertSame('https://x.test/s/content-schema/x/1', $this->resolver('https://x.test/s')->resolveId($ref));
        $this->assertSame('content-schema/x/1', $this->resolver(false)->resolveId($ref));

        $this->expectException(UnresolvableRelativeSchemaId::class);
        $this->resolver(null)->resolveId($ref);
    }

    #[DataProvider('everyAuthorityState')]
    public function test_an_absolute_ref_survives_every_authority_state(string|bool|null $baseUri): void
    {
        $absolute = 'https://schemas.x.test/a/b/1';

        $this->assertSame($absolute, $this->resolver($baseUri)->resolveId($absolute));
    }

    public static function everyAuthorityState(): array
    {
        return [
            'declared' => ['https://app.splicewire.com/schemas'],
            'opted out' => [false],
            'unset' => [null],
            'not an origin' => ['/schemas'],
        ];
    }

    // ---------------------------------------------------------------- the seam

    public function test_a_registered_parser_claims_a_ref_before_the_default_grammar(): void
    {
        $resolver = $this->resolver(self::AUTHORITY, [PrefixingSchemaIdParser::class]);

        // `demo/guest-intake` is a bare slug the registrant promotes into its namespace. The default
        // grammar would have resolved it verbatim, one segment short.
        $this->assertSame(
            'https://app.splicewire.com/schemas/content-schema/demo/guest-intake',
            $resolver->resolveId('demo/guest-intake'),
        );
        $this->assertSame(
            'https://app.splicewire.com/schemas/content-schema/demo/guest-intake',
            $resolver->resolveId('demo/guest-intake.json'),
        );
    }

    public function test_a_ref_the_registrant_does_not_claim_falls_to_the_default_grammar(): void
    {
        $resolver = $this->resolver(self::AUTHORITY, [PrefixingSchemaIdParser::class]);

        // Already spelled in full — the registrant declines, and the floor answers. This is what
        // makes the floor unshadowable: it is not an entry in the list, so no ordering can starve it.
        $this->assertSame(
            'https://app.splicewire.com/schemas/content-schema/demo/guest-intake/1',
            $resolver->resolveId('content-schema/demo/guest-intake/1'),
        );
        $this->assertInstanceOf(
            RelativeSchemaIdParser::class,
            $resolver->parserFor('content-schema/demo/guest-intake/1'),
        );
        $this->assertInstanceOf(
            PrefixingSchemaIdParser::class,
            $resolver->parserFor('demo/guest-intake'),
        );
    }

    public function test_a_registrant_takes_the_authority_from_the_host_rather_than_carrying_one(): void
    {
        $ref = 'demo/guest-intake';

        $this->assertSame(
            'https://tower.test/schemas/content-schema/demo/guest-intake',
            $this->resolver('https://tower.test/schemas', [PrefixingSchemaIdParser::class])->resolveId($ref),
        );

        // And it inherits the tri-state rather than answering it privately — which is the exact
        // thing ContentSchemaId could not do.
        $this->expectException(UnresolvableRelativeSchemaId::class);
        $this->resolver(null, [PrefixingSchemaIdParser::class])->resolveId($ref);
    }

    public function test_an_instance_registrant_is_used_as_given(): void
    {
        $resolver = $this->resolver(self::AUTHORITY, [new PrefixingSchemaIdParser]);

        $this->assertSame(
            'https://app.splicewire.com/schemas/content-schema/demo/guest-intake',
            $resolver->resolveId('demo/guest-intake'),
        );
    }

    public function test_a_registrant_that_cannot_be_built_is_skipped_rather_than_fatal(): void
    {
        // Somebody else's broken config entry must not take down every schema id at the host.
        $resolver = $this->resolver(self::AUTHORITY, ['Not\\A\\Class', \stdClass::class, PrefixingSchemaIdParser::class]);

        $this->assertSame(
            'https://app.splicewire.com/schemas/content-schema/demo/guest-intake',
            $resolver->resolveId('demo/guest-intake'),
        );
    }

    public function test_the_first_claimer_wins_and_the_second_never_runs(): void
    {
        // PickOne, not a pipeline: two parsers rewriting the same string in turn would be a grammar
        // nobody declared.
        $second = new class implements \Schemastud\DataSchemas\Ids\SchemaIdParser
        {
            public function handles(string $ref): bool
            {
                return true;
            }

            public function parse(string $ref, string|bool|null $baseUri): \Schemastud\JsonNs\NamespaceUri
            {
                return \Schemastud\JsonNs\NamespaceUri::from('never/reached');
            }
        };

        $resolver = $this->resolver(self::AUTHORITY, [new PrefixingSchemaIdParser, $second]);

        $this->assertSame(
            'https://app.splicewire.com/schemas/content-schema/demo/guest-intake',
            $resolver->resolveId('demo/guest-intake'),
        );
    }

    // ---------------------------------------------------------------- one rule, three spellings

    public function test_every_undeclared_authority_failure_is_catchable_as_one_rule(): void
    {
        $this->assertInstanceOf(UndeclaredSchemaAuthority::class, new UnresolvableRelativeSchemaId('a/b/1', null));
        $this->assertInstanceOf(UndeclaredSchemaAuthority::class, new MissingSchemaBaseUri('Some\\Data'));
        $this->assertInstanceOf(UndeclaredSchemaAuthority::class, new NonAbsoluteSchemaBaseUri('Some\\Data', '/schemas'));
    }

    public function test_no_default_authority_is_reachable_in_any_spelling(): void
    {
        // The deleted package default reached three vendors and named an unregistered domain. A `??`
        // argument is the same default in a shorter spelling — this asserts neither exists.
        foreach ([null, '', '   ', '/schemas', 'schemas.example.test'] as $nonAuthority) {
            try {
                $this->resolver($nonAuthority)->resolveId('content-schema/x/1');
                $this->fail(sprintf('A relative ref resolved under a non-authority (%s).', var_export($nonAuthority, true)));
            } catch (UnresolvableRelativeSchemaId $e) {
                $this->assertStringNotContainsString('splicewire.app', $e->getMessage());
            }
        }
    }
}
