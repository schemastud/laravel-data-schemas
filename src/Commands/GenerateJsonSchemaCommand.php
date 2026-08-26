<?php

namespace Schemastud\DataSchemas\Commands;

use Illuminate\Console\Command;
use Schemastud\DataSchemas\Actions\DiscoverDataClassesAction;
use Schemastud\DataSchemas\Actions\GenerateSchemasAction;
use Schemastud\DataSchemas\Generators\ChainedGenerator;
use Schemastud\DataSchemas\PathGenerators\PathGenerator;
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

        // Instantiate collectors
        $collectors = $this->instantiateCollectors($config);

        // Discover Data classes
        $discoverAction = new DiscoverDataClassesAction($config, $collectors);
        $classes = $discoverAction->execute(
            path: $this->option('path'),
            className: $this->option('class')
        );

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

        // Write schemas to disk
        $writer = $this->instantiateWriter($config);
        $writer->write($collection);

        // Display results
        $this->newLine();
        $this->table(
            ['Class', 'Output Path', 'Properties'],
            $collection->map(fn ($schema) => [
                $schema->className,
                str_replace(base_path().'/', '', $schema->outputPath),
                $schema->getPropertyCount(),
            ])
        );

        $this->newLine();
        $this->info("✓ Generated {$collection->count()} JSON Schema file(s)");

        return self::SUCCESS;
    }

    protected function buildConfig(): array
    {
        $config = config('data-schemas');

        // Override output directory if specified
        if ($outputDir = $this->option('output')) {
            $config['output_directory'] = $outputDir;
        }

        return $config;
    }

    protected function instantiateCollectors(array $config): array
    {
        return array_map(
            fn (string $class) => new $class($config),
            $config['collectors']
        );
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
