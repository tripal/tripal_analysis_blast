<?php

namespace Drupal\Tests\tripal_analysis_blast\Unit\Hook;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\tripal_analysis_blast\Hook\TripalAnalysisBlastHooks;
use Drupal\tripal_analysis_blast\Services\TripalAnalysisBlastRebuildService;

/**
 * Tests TripalAnalysisBlastHooks::help().
 */
class TripalAnalysisBlastHooksTest extends UnitTestCase {

  /**
   * Tests the help output for the module help page.
   */
  public function testHelpPage(): void {
    $route_match = $this->createMock(RouteMatchInterface::class);

    // Create a mock dependency for the hooks class.
    $rebuild_service = $this->createMock(TripalAnalysisBlastRebuildService::class);
    $hooks = new TripalAnalysisBlastHooks($rebuild_service);
    $hooks->setStringTranslation($this->getStringTranslationStub());

    $output = $hooks->help('help.page.tripal_analysis_blast', $route_match);

    $this->assertIsString($output, 'Expect that output from the help hook is a string');
    $this->assertStringContainsString('About', $output, 'Expect the "About" header to be present');
    $this->assertStringContainsString('An analysis sub-module for adding, editing, and displaying blast analysis results.',
      $output, 'Expect to have help text from the help hook');
  }

  /**
   * Tests that there is no help output for an unsupported route.
   */
  public function testHelpUnknownRoute(): void {
    $route_match = $this->createMock(RouteMatchInterface::class);

    // Create a mock dependency for the hooks class.
    $rebuild_service = $this->createMock(TripalAnalysisBlastRebuildService::class);
    $hooks = new TripalAnalysisBlastHooks($rebuild_service);
    $hooks->setStringTranslation($this->getStringTranslationStub());

    $output = $hooks->help('help.page.tripal_chado', $route_match);

    $this->assertNull($output, 'Expect no output on a help route not from this module');
  }

}
