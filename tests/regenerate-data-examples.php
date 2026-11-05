<?php

/*
 * The `docs:examples` command (`composer docs:examples`, then `-- --write`): capture each data example under
 * docs/registries/examples in a fresh application booted as DataExamplesTestCase boots it, and print what would
 * change. Only --write writes. The verification half is tests/DataExamplesTest.php, which never writes.
 */

use Rushing\Popcorn\Registries\Docs\DataExamples;
use Schemastud\DataSchemas\Tests\DataExamplesTestCase;

require __DIR__.'/../vendor/autoload.php';

exit(DataExamples::main($argv, dirname(__DIR__), fn () => (new class('data-examples') extends DataExamplesTestCase {})->createApplication()));
