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
}
