<?php

namespace Schemastud\DataSchemas\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Schemastud\DataSchemas\Actions\DiscoverDataClassesAction;
use Schemastud\DataSchemas\Actions\GenerateSchemasAction;
use Schemastud\DataSchemas\Generators\ChainedGenerator;
use Schemastud\DataSchemas\PathGenerators\PathGenerator;
use Schemastud\DataSchemas\Sources\ExplicitClassSource;
use Schemastud\DataSchemas\Sources\PathScanSource;
use Schemastud\DataSchemas\Sources\SchemaProjectionRegistry;
use Schemastud\DataSchemas\Sources\SchemaSource;
use Schemastud\DataSchemas\Support\SchemaDisk;
use Schemastud\DataSchemas\Support\WrittenSchema;
use Schemastud\DataSchemas\Writers\Writer;

class GenerateJsonSchemaCommand extends Command
{
    protected $signature = 'schemas:generate
                            {--path= : Specific path to scan}
                            {--output= : Override output directory}
                            {--class= : Generate for specific class}';

    protected $description = 'Generate JSON Schemas from Laravel Data objects';

    public function handle(): int
    {
        $this->info('Generating JSON Schemas...');

        // Build configuration
        $config = $this->buildConfig();

        // Discover Data classes — through the registry, so every source this host has registered is
        // enumerated, not just the path scan this command used to hard-code.
        $classes = $this->source($config)->classes();

        if (empty($classes)) {
            $this->warn('No Data classes found to generate schemas for.');

            return self::SUCCESS;
        }

        $this->info('Found '.count($classes).' Data class(es)');

        // Generate schemas
        $generators = $this->instantiateGenerators($config);
        $pathGenerator = $this->instantiatePathGenerator($config);

        $generateAction = new GenerateSchemasAction($generators, $pathGenerator);
        $collection = $generateAction->execute($classes);

        // A path claimed by two classes is a last-write-wins overwrite, and it used to happen
        // in silence — `path_structure: 'flat'` keys on the short name, so two same-named
        // classes in different namespaces produce one file. Reported BEFORE the write, so the
        // operator sees it even when the run is otherwise unremarkable. Advisory, not fatal:
        // whether two classes may share a name is a fact about the HOST's namespaces.
        foreach ($collection->pathCollisions() as $path => $colliding) {
            $this->warn('Path collision — '.implode(' and ', $colliding).' both write to '.$path);
        }

        // Write schemas to disk
        $writer = $this->instantiateWriter($config);
        $writer->write($collection);

        // Display results
        $this->newLine();
        $this->table(
            ['Class', 'Output Path', 'Properties'],
            $collection->map(fn (WrittenSchema $schema) => [
                $schema->className,
                str_replace(base_path().'/', '', $schema->outputPath),
                $schema->getPropertyCount(),
            ])->all()
        );

        $this->newLine();
        $this->info("✓ Generated {$collection->count()} JSON Schema file(s)");

        return self::SUCCESS;
    }

    /**
     * The `--output` override moves the DISK ROOT with the directory, and forgets the
     * already-resolved disk so the next `Storage::disk()` picks the new root up. The disk is
     * defined at register time from `output_directory`; changing only one of the two would
     * leave the writer emitting disk-relative paths derived from one root into a disk rooted
     * at another — files in the wrong place, and no error anywhere.
     */
    protected function buildConfig(): array
    {
        $config = config('data-schemas');

        if ($outputDir = $this->option('output')) {
            $config['output_directory'] = $outputDir;

            $disk = SchemaDisk::name($config);
            config(['filesystems.disks.'.$disk.'.root' => $outputDir]);
            Storage::forgetDisk($disk);
        }

        return $config;
    }

    /**
     * WHERE this run's classes come from.
     *
     * Ordinarily the whole {@see SchemaProjectionRegistry} — the union of every registered source,
     * deduped by class name with the first source to name a class keeping the slot. That is what makes
     * a contributed universe (beam's particle registry, an explicit manifest, a table of tenant shapes)
     * generable without this command knowing it exists.
     *
     * An explicit `--class` or `--path` REPLACES that registry with an ad-hoc source rather than
     * filtering its union. The reasoning, and what it means when a host has registered a second source,
     * is written out on {@see ExplicitClassSource} — the short version is that filtering would quietly
     * narrow `--class` from "generate this class" to "generate this class if something already
     * discovers it", which is the case the flag exists for.
     *
     * `--class` beats `--path` when both are given, which is the precedence
     * {@see DiscoverDataClassesAction::execute()} already had.
     */
    protected function source(array $config): SchemaSource
    {
        if ($className = $this->option('class')) {
            return new ExplicitClassSource($className, $config);
        }

        if ($path = $this->option('path')) {
            return new PathScanSource(['auto_discover_types' => [$path]] + $config);
        }

        return app(SchemaProjectionRegistry::class);
    }

    /**
     * Through {@see ChainedGenerator::fromConfig()} rather than a local `array_map`: this method and
     * the provider's `Generator` binding were the same loop written twice, and only one of them
     * validated its entries. The action still takes a LIST, so the chain is unwrapped here.
     */
    protected function instantiateGenerators(array $config): array
    {
        return ChainedGenerator::fromConfig($config)->generators();
    }

    protected function instantiatePathGenerator(array $config): PathGenerator
    {
        $class = $config['path_generator'];

        return new $class($config);
    }

    protected function instantiateWriter(array $config): Writer
    {
        $class = $config['writer'];

        return new $class($config);
    }
}
