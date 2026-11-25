<?php

namespace Drupal\Tests\tripal_analysis_blast\Unit\Hook;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\tripal_analysis_blast\Hook\TripalAnalysisBlastHooks;
use Drupal\tripal_analysis_blast\Services\TripalAnalysisBlastRebuildService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests TripalAnalysisBlastHooks::help().
 */
#[Group('tripal-analysis-blast')]
#[RunTestsInSeparateProcesses]
class TripalAnalysisBlastHooksTest extends UnitTestCase {

  /**
   * Tests the help output for the module help page.
   */
  public function testHelpPage(): void {
    // Create a mock dependency for the hooks class.
    $rebuild_service = $this->createMock(TripalAnalysisBlastRebuildService::class);
    $hooks = new TripalAnalysisBlastHooks($rebuild_service);
    $hooks->setStringTranslation($this->getStringTranslationStub());

    $route_match = $this->createMock(RouteMatchInterface::class);
    $output = $hooks->help('help.page.tripal_analysis_blast', $route_match);

    $this->assertIsString($output, 'Expect that output from the help hook is a string');
    $this->assertStringContainsString('About', $output, 'Expect the "About" header to be present');
    $this->assertStringContainsString('An analysis sub-module for adding, editing, and displaying blast analysis results.',
      $output, 'Expect to have help text from the help hook');
  }

  /**
   * Tests that there is no help output for an unsupported route.
   */
  public function testHelpForUnknownRoute(): void {
    // Create a mock dependency for the hooks class.
    $rebuild_service = $this->createMock(TripalAnalysisBlastRebuildService::class);
    $hooks = new TripalAnalysisBlastHooks($rebuild_service);
    $hooks->setStringTranslation($this->getStringTranslationStub());

    $route_match = $this->createMock(RouteMatchInterface::class);
    $output = $hooks->help('help.page.tripal_chado', $route_match);

    $this->assertNull($output, 'Expect no output on a help route not from this module');
  }

}
