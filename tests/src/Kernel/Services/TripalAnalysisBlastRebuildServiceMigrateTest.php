<?php

namespace Drupal\Tests\tripal\Kernel\Services;

use Drupal\Tests\tripal_chado\Kernel\ChadoTestKernelBase;
use Drupal\tripal_analysis_blast\Services\TripalAnalysisBlastRebuildService;
use Drupal\tripal_chado\Database\ChadoConnection;
use Drupal\tripal_chado\Plugin\ChadoBuddy\ChadoDbxrefBuddy;
use Drupal\tripal_chado\Plugin\ChadoBuddy\ChadoCvtermBuddy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the migrateTripalTerms() function in the rebuild service.
 */
#[Group('service-rebuild')]
#[Group('tripal-analysis-blast')]
#[RunTestsInSeparateProcesses]
class TripalAnalysisBlastRebuildServiceMigrateTest extends ChadoTestKernelBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['system', 'user', 'tripal', 'tripal_chado', 'tripal_analysis_blast', 'filter', 'views'];

  /**
   * A dbxref buddy to help us install terms.
   *
   * @var Drupal\tripal_chado\Plugin\ChadoBuddy\ChadoDbxrefBuddy
   */
  protected ChadoDbxrefBuddy $dbxref_buddy;

  /**
   * A cvterm buddy to help us install terms.
   *
   * @var Drupal\tripal_chado\Plugin\ChadoBuddy\ChadoCvtermBuddy
   */
  protected ChadoCvtermBuddy $cvterm_buddy;

  /**
   * The test chado connection.
   *
   * @var Drupal\tripal_chado\Database\ChadoConnection
   */
  protected ChadoConnection $chado_connection;

  /**
   * The rebuild service for the tripal_analysis_blast module.
   *
   * @var Drupal\tripal_analysis_blast\Services\TripalAnalysisBlastRebuildService
   */
  protected TripalAnalysisBlastRebuildService $rebuild_service;

  /**
   * The YAML file indicating the scenarios to test and how to setup the enviro.
   *
   * @var string
   */
  protected string $yaml_info_file = __DIR__ . '/TripalAnalysisBlastRebuildServiceMigrateTest-TestInfo.yml';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Initialize services.
    $this->chado_connection = $this->createTestSchema(ChadoTestKernelBase::INIT_CHADO_EMPTY);
    $buddy_service = $this->container->get('tripal_chado.chado_buddy');
    $this->dbxref_buddy = $buddy_service->createInstance('chado_dbxref_buddy', []);
    $this->cvterm_buddy = $buddy_service->createInstance('chado_cvterm_buddy', []);
    $this->rebuild_service = $this->container->get('tripal_analysis_blast.rebuild_service');
  }

  /**
   * Tests the migrateTripalTerms() function.
   *
   * This function is only called on module install.
   * We will first simulate a migrated tripal 3 chado instance, and then
   * verify that this function updates all of the terms correctly.
   */
  public function testTripalAnalysisBlastMigrateTripalTerms() {

    // Create a legacy 'tripal' database.
    $this->dbxref_buddy->insertDb([
      'db.name' => 'tripal',
      'db.description' => '',
      'db.urlprefix' => '',
      'db.url' => '',
    ]);

    // Create a new 'local' database for migration.
    // Unlike the 'local' CV, this will not have been created yet.
    $this->dbxref_buddy->insertDb([
      'db.name' => 'local',
      'db.description' => 'Terms created for this site',
      'db.urlprefix' => 'cv/lookup/{db}/{accession}',
      'db.url' => 'cv/lookup/local',
    ]);

    // Create a legacy 'tripal' controlled vocabulary.
    $this->cvterm_buddy->insertCv([
      'cv.name' => 'tripal',
      'cv.definition' => '',
    ]);

    // Create the terms used by the tripal 3 version of this module.
    // These were all stored under the 'tripal' CV in Tripal 3,
    // with dbxref accessions linking to the 'tripal' DB.
    $yaml = Yaml::parseFile($this->yaml_info_file);
    $terms = $yaml['tripal3terms'];

    foreach ($terms as $term) {
      [$db_name, $dbxref_accession] = explode(':', $term['id']);
      $cvterm_record = $this->cvterm_buddy->insertCvterm([
        'db.name' => $db_name,
        'dbxref.accession' => $dbxref_accession,
        'cv.name' => 'tripal',
        'cvterm.name' => $term['name'],
        'cvterm.definition' => $term['description'],
      ]);
      $cvterm_id = $cvterm_record->getValue('cvterm.cvterm_id');
      $this->assertMatchesRegularExpression('/^\d+$/', $cvterm_id, 'Failed inserting cvterm record');
    }

    // Call migrateTripalTerms().
    $report = $this->rebuild_service->migrateTripalTerms();
    $this->assertStringContainsString('tripal_analysis_blast: Updating 26 dbxref records from tripal to local DB.', $report,
      'Did not receive the expected migration report');
    $this->assertStringContainsString('tripal_analysis_blast: Updating 26 cvterm records from tripal to local CV.', $report,
      'Did not receive the expected migration report');

    // Validate that all of the terms are now migrated.
    foreach ($terms as $term) {
      // Check that term is not present under 'tripal'.
      $cvterm_record = $this->cvterm_buddy->getCvterm([
        'db.name' => 'tripal',
        'cv.name' => 'tripal',
        'cvterm.name' => $term['name'],
      ]);
      $this->assertEmpty($cvterm_record, 'Term is still present in its unmigrated state: "' . $term['name'] . '"');

      // Check that term is present under 'local'.
      $cvterm_record = $this->cvterm_buddy->getCvterm([
        'db.name' => 'local',
        'cv.name' => 'local',
        'cvterm.name' => $term['name'],
      ]);
      $this->assertIsArray($cvterm_record, 'Buddy query did not return an array for term "' . $term['name'] . '"');
      $values = $cvterm_record[0]->getValues();
      $this->assertNotEmpty($values, 'Failed to migrate term "' . $term['name'] . '"');
    }
  }

}
