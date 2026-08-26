<?php

use Rushing\PipelineRegistry\Stages\CopyFilesPipeline;
use Schemastud\DataSchemas\Pipelines\GenerateJsonSchemasStage;
use Schemastud\DataSchemas\Pipelines\TransformTypesStage;

/**
 * The `resources:schemastud` pipeline — projects the OPEN, foundation-tier slice
 * of the app's generated types into @schemastud/_resources. Registered by this
 * package's provider via PipelineRegistry::mergePipelinesFrom(); the filename
 * (`resources`) is the category and the key (`schemastud`) the sub-name, so this
 * becomes `resources:schemastud`.
 *
 * Note the app-coupling: `source` (the app's generated .d.ts) and the emit `to`
 * (a sibling workspace package) are host/machine facts, so they arrive via env
 * with app-relative defaults rather than being baked in. (A foundation package
 * shipping a pipeline that names app paths is a real seam wrinkle — see the 08
 * lessons: the alternative is the app owning every resources.php entry.)
 */
return [
    'schemastud' => [
        [TransformTypesStage::class, [
            'scope' => 'schemastud',
            'source' => env('RESOURCES_TYPES_SOURCE', base_path('ui/src/types/generated.d.ts')),
            'emit' => 'types/foundation.d.ts',
            // Emptied by registry-kernel ticket 65. These named two TypeScript types,
            // `JsonSchemaData` and `JsonSchemaRefData`, lifted out of the app's generated
            // `.d.ts`. Both were projections of `Splicewire\Tower\Data\JsonSchema*Data`,
            // which that ticket DELETED along with the rest of the `/json-schemas` family —
            // so the generator no longer emits either name and this stage was selecting for
            // types that cannot appear. Left as an empty list rather than removing the stage:
            // the pipeline is the seam a foundation type joins through, and the next one to
            // be lifted belongs here.
            'types' => [],
        ]],
        // Foundation schemas ride the same rails when a schema-driven surface
        // arrives; no foundation Data class is wired to emit yet (type-only today).
        [GenerateJsonSchemasStage::class, [
            'data' => [],
            'emit' => 'schemas',
        ]],
        [CopyFilesPipeline::class, [
            'to' => env('SCHEMASTUD_RESOURCES_DIR', base_path('../../Workspaces/js/packages/schemastud/_resources')),
        ]],
    ],
];
