<?php

namespace Schemastud\DataSchemas\Tests;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Schemastud\DataSchemas\Support\SchemaAuthority;

/**
 * `originOf()` — the structural half of beam-facade ticket 104's authority-ownership rule.
 *
 * `hostOf()` already existed and is deliberately lossy: it drops the scheme and the port because its
 * caller ({@see \Schemastud\DataSchemas\Http\SchemaDoorMount::domainFor()}) is choosing a route's
 * domain constraint, where neither matters. 104 asks a different question — do two URIs come from the
 * SAME PLACE — and `http://a.test` vs `https://a.test:8443` answers it differently from `hostOf()`.
 *
 * This stays tenant-blind on purpose. It is told nothing about who owns what; a caller that knows
 * what a tenant is compares two origins and draws the conclusion.
 */
class SchemaAuthorityOriginTest extends TestCase
{
    public static function origins(): array
    {
        return [
            'a bare origin is its own origin' => ['https://schemas.example.test', 'https://schemas.example.test'],
            'the path is discarded' => ['https://app.splicewire.com/schemas', 'https://app.splicewire.com'],
            'a full schema $id reduces to its origin' => ['https://demo.app.splicewire.test/schemas/invoice/1', 'https://demo.app.splicewire.test'],
            'an explicit port is PART of the origin' => ['https://demo.app.test:8443/schemas/x/1', 'https://demo.app.test:8443'],
            'the scheme is part of it too' => ['http://demo.app.test/schemas/x/1', 'http://demo.app.test'],
            'a query and fragment are discarded' => ['https://a.test/schemas/x/1?v=2#frag', 'https://a.test'],
            'surrounding whitespace is trimmed, matching isAbsolute()' => ['  https://a.test/schemas  ', 'https://a.test'],

            // The three non-authorities, answering exactly as `isAbsolute()` does — this method is a
            // reading of that predicate, never a second opinion about what counts as an authority.
            'a relative path names no origin' => ['/schemas', null],
            'a bare host with no scheme names none either' => ['schemas.example.test', null],
            'the empty string is not a declaration' => ['', null],
            'unset is undecided' => [null, null],
            'false is an opt-out, not an origin' => [false, null],
        ];
    }

    #[DataProvider('origins')]
    public function test_it_reduces_an_absolute_uri_to_its_origin(string|bool|null $uri, ?string $expected): void
    {
        $this->assertSame($expected, SchemaAuthority::originOf($uri));
    }

    public function test_it_never_disagrees_with_is_absolute(): void
    {
        foreach (self::origins() as $case) {
            [$uri, $expected] = $case;

            $this->assertSame(
                SchemaAuthority::isAbsolute($uri),
                $expected !== null,
                'originOf() and isAbsolute() must answer the same question about '.var_export($uri, true),
            );
        }
    }
}
