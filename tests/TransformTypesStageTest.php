<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use Rushing\PipelineRegistry\PipelineContext;
use Schemastud\DataSchemas\Pipelines\TransformTypesStage;

// The pipeline-registry engine is required `dev-main` from an as-yet-unpushed VCS repo, so it
// is not installed in this package's isolated vendor (a co-dev canonicalization gap tracked
// under rehome-components 08). Load the passable directly from its workspace-sibling path so
// this stage test runs without that dependency resolved; it autoloads normally once the engine
// is published.
if (! class_exists(PipelineContext::class)) {
    $enginePath = __DIR__.'/../../../rushing/laravel-pipeline-registry/src/PipelineContext.php';
    if (is_file($enginePath)) {
        require_once $enginePath;
    }
}

class TransformTypesStageTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        // A minimal stand-in for the app's generated `.d.ts`: an object type that
        // references a sibling enum by its namespaced name, plus that enum.
        //
        // The second root is NOT decoration. This fixture carried only `App` until 2026-09-03,
        // which is the namespace the estate has migrated OFF — so every assertion below passed
        // against a rewriter and a dangling-ref detector that were inert on every real slice.
        // `Splicewire` here is what makes the tests able to fail. It also reproduces the short-
        // name collision (`CalendarEventData` under two roots) that first-match resolution
        // decided silently.
        $this->source = tempnam(sys_get_temp_dir(), 'gen').'.d.ts';
        file_put_contents($this->source, <<<'TS'
        declare namespace App {
        export namespace Data {
        export type ApiTokenData = {
        id: number,
        provenance: App.Enums.TokenProvenance,
        abilities: string[] | null,
        };
        }
        export namespace Enums {
        export type TokenProvenance = 'api' | 'session';
        }
        }
        namespace Splicewire {
        namespace Beam {
        namespace Calendars {
        namespace Data {
        export type CalendarEventData = {
        calendarId: string,
        };
        export type WalletBalanceData = {
        ledger: Splicewire.Beam.Calendars.Data.CreditEntryData[],
        role: Splicewire.Beam.Accounts.Data.RoleOptionData | null,
        };
        export type CreditEntryData = {
        id: string,
        };
        }
        }
        }
        }
        namespace Splicewire {
        namespace Tower {
        namespace Data {
        export type CalendarEventData = {
        cell_id: string | null,
        };
        }
        }
        }
        TS);
    }

    protected function tearDown(): void
    {
        @unlink($this->source);
    }

    private function emit(array $types): PipelineContext
    {
        $stage = new TransformTypesStage([
            'source' => $this->source,
            'scope' => 'test',
            'emit' => 'types/test.d.ts',
            'types' => $types,
        ]);

        return $stage->handle(new PipelineContext, fn ($c) => $c);
    }

    public function test_it_rewrites_namespaced_refs_to_co_sliced_siblings_to_bare_names(): void
    {
        $out = $this->emit(['ApiTokenData', 'TokenProvenance'])->files['types/test.d.ts'];

        // The sibling reference is rewritten to the bare local name the slice emits...
        $this->assertStringContainsString('provenance: TokenProvenance,', $out);
        // ...and no dangling `App.*` namespace survives in a self-contained module.
        $this->assertStringNotContainsString('App.Enums', $out);
        $this->assertStringContainsString("export type TokenProvenance = 'api' | 'session';", $out);
    }

    public function test_it_notes_a_dangling_ref_when_the_referenced_type_is_not_sliced(): void
    {
        $context = $this->emit(['ApiTokenData']); // deliberately omit TokenProvenance
        $out = $context->files['types/test.d.ts'];

        // Un-sliced reference stays namespaced (still broken) but is surfaced as a note so
        // the pipeline author knows to add it to the `types` slice.
        $this->assertStringContainsString('App.Enums.TokenProvenance', $out);
        $this->assertContains(
            'TransformTypesStage: DANGLING ref [App.Enums.TokenProvenance] — add its type to the [types] slice',
            $context->log,
        );
    }

    public function test_it_rewrites_sibling_refs_under_a_non_app_namespace_root(): void
    {
        $out = $this->emit(['WalletBalanceData', 'CreditEntryData'])->files['types/test.d.ts'];

        $this->assertStringContainsString('ledger: CreditEntryData[],', $out);
        $this->assertStringNotContainsString('Splicewire.Beam.Calendars.Data.CreditEntryData', $out);
    }

    public function test_it_notes_a_dangling_ref_under_a_non_app_namespace_root(): void
    {
        $context = $this->emit(['WalletBalanceData', 'CreditEntryData']);

        $this->assertContains(
            'TransformTypesStage: DANGLING ref [Splicewire.Beam.Accounts.Data.RoleOptionData] '
            .'— add its type to the [types] slice',
            $context->log,
        );
    }

    public function test_a_short_name_declared_twice_is_reported_rather_than_silently_first_matched(): void
    {
        $context = $this->emit(['CalendarEventData']);

        $note = implode("\n", $context->log);
        $this->assertStringContainsString('AMBIGUOUS CalendarEventData — 2 declarations', $note);
        $this->assertStringContainsString('Splicewire.Beam.Calendars.Data.CalendarEventData', $note);
        $this->assertStringContainsString('Splicewire.Tower.Data.CalendarEventData', $note);
    }

    public function test_a_qualified_name_selects_the_declaration_the_short_name_would_not(): void
    {
        $context = $this->emit(['Splicewire.Tower.Data.CalendarEventData']);
        $out = $context->files['types/test.d.ts'];

        // First-match on the short name yields the beam-calendars camelCase shape; the
        // qualified name must reach past it to tower's snake_case one.
        $this->assertStringContainsString('cell_id: string | null,', $out);
        $this->assertStringNotContainsString('calendarId', $out);
        // ...and it emits under the BARE name, because the bundle is a flat module.
        $this->assertStringContainsString('export type CalendarEventData = {', $out);
        $this->assertNotContains(
            'TransformTypesStage: AMBIGUOUS Splicewire.Tower.Data.CalendarEventData',
            $context->log,
        );
    }

    public function test_qualified_sibling_rewriting_preserves_the_selected_declarations_identity(): void
    {
        file_put_contents($this->source, <<<'TS'
        namespace Splicewire {
        namespace Beam {
        export type EventData = {
        calendarId: string,
        };
        export type FeedData = {
        event: Splicewire.Beam.EventData,
        };
        }
        namespace Tower {
        export type EventData = {
        cell_id: string,
        };
        }
        }
        TS);

        $context = $this->emit(['Splicewire.Beam.FeedData', 'Splicewire.Tower.EventData']);
        $out = $context->files['types/test.d.ts'];

        $this->assertStringContainsString('cell_id: string,', $out);
        $this->assertStringContainsString('event: Splicewire.Beam.EventData,', $out);
        $this->assertContains(
            'TransformTypesStage: DANGLING ref [Splicewire.Beam.EventData] — add its type to the [types] slice',
            $context->log,
        );

        $context = $this->emit(['Splicewire.Beam.FeedData', 'Splicewire.Beam.EventData']);
        $out = $context->files['types/test.d.ts'];

        $this->assertStringContainsString('calendarId: string,', $out);
        $this->assertStringContainsString('event: EventData,', $out);
        $this->assertStringNotContainsString('Splicewire.Beam.EventData', $out);
        $this->assertCount(2, $context->log);
    }

    public function test_the_banner_identifies_the_generated_typescript_projection(): void
    {
        $out = $this->emit(['ApiTokenData', 'TokenProvenance'])->files['types/test.d.ts'];

        $this->assertStringStartsWith(
            "// GENERATED — do not edit by hand.\n"
            ."// Projected from generated TypeScript via resources:test.\n\n",
            $out,
        );
    }
}
