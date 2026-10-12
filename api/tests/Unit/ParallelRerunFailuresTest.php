<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use PHPUnit\Framework\TestCase;

\defined('MYINVOICE_PARALLEL_RUNNER_FUNCTIONS_ONLY') || \define('MYINVOICE_PARALLEL_RUNNER_FUNCTIONS_ONLY', true);
require_once dirname(__DIR__, 2) . '/bin/test-parallel.php';

final class ParallelRerunFailuresTest extends TestCase
{
    public function testFailedClassesAreReadFromFailuresAndErrorsOnce(): void
    {
        $path = sys_get_temp_dir() . '/parallel-rerun-' . bin2hex(random_bytes(6)) . '.xml';
        file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="Application">
    <testsuite name="A\CronTest">
      <testcase name="testOne" class="A\CronTest"><failure>proc_open</failure></testcase>
      <testcase name="testTwo" class="A\CronTest"><failure>proc_open</failure></testcase>
      <testcase name="testOk" class="A\OkTest"/>
      <testcase name="testErr" class="B\MigrationTest"><error>boom</error></testcase>
      <testcase name="testSkipped" class="C\SkipTest"><skipped/></testcase>
    </testsuite>
  </testsuite>
</testsuites>
XML);
        try {
            self::assertSame(['A\CronTest', 'B\MigrationTest'], \failedTestClasses($path));
        } finally {
            unlink($path);
        }
    }

    public function testMissingReportMeansNothingToRerun(): void
    {
        self::assertSame([], \failedTestClasses(sys_get_temp_dir() . '/missing-' . bin2hex(random_bytes(6)) . '.xml'));
    }
}
