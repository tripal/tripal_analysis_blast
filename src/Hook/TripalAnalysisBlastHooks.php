<?php

namespace Drupal\tripal_analysis_blast\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tripal_analysis_blast\Services\TripalAnalysisBlastRebuildService;

/**
 * Hook implementations for the Tripal Analysis Blast module.
 */
class TripalAnalysisBlastHooks {

  use StringTranslationTrait;

  /**
   * The rebuild service for this module.
   *
   * @var \Drupal\tripal_analysis_blast\Services\TripalAnalysisBlastRebuildService
   */
  protected TripalAnalysisBlastRebuildService $rebuildService;

  /**
   * Constructor.
   *
   * @param \Drupal\tripal_analysis_blast\Service\RebuildService $rebuildService
   *   The rebuild service for this module.
   */
  public function __construct(TripalAnalysisBlastRebuildService $rebuildService) {
    $this->rebuildService = $rebuildService;
  }

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help($route_name, RouteMatchInterface $route_match) {
    switch ($route_name) {
      // Main module help for the tripal_analysis_blast module.
      case 'help.page.tripal_analysis_blast':
        $output = '<h3>' . $this->t('About') . '</h3>';
        $output .= '<p>' . $this->t('An analysis sub-module for adding, editing, and displaying blast analysis results.') . '</p>';
        return $output;

      default:
    }
  }

  /**
   * Implements hook_rebuild().
   */
  #[Hook('rebuild')]
  public function rebuild(): string {
    $this->rebuildService->executeRebuild();
    // Return value of the module name is only used for phpunit tests.
    return 'tripal_analysis_blast';
  }

  /**
   * Implements hook_uninstall().
   *
   * The hook uninstall does not support attributes and must remain procedural.
   */
  public function tripalAnalysisBlastUninstall() {
    // Delete the custom tables created by this module.
    $this->rebuildService->dropCustomChadoTables();
  }

}
