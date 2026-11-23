<?php

namespace Drupal\Tests\tripal\Kernel\Services;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Extension\ModuleInstaller;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\pgsql\Driver\Database\pgsql\Connection;
use Drupal\Tests\tripal_chado\Kernel\ChadoTestKernelBase;
use Drupal\tripal_chado\Database\ChadoConnection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the rebuild hooks and services.
 */
#[Group('service-rebuild')]
#[Group('tripal-analysis-blast')]
#[RunTestsInSeparateProcesses]
class TripalAnalysisBlastRebuildServiceTest extends ChadoTestKernelBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['system', 'user', 'tripal', 'tripal_chado', 'tripal_analysis_blast', 'filter', 'views'];

  /**
   * Module installer (and uninstaller) service.
   *
   * @var Drupal\Core\Extension\ModuleInstaller
   */
  protected ModuleInstaller $module_installer;

  /**
   * Module handler service.
   *
   * @var Drupal\Core\Extension\ModuleHandler
   */
  protected ModuleHandler $module_handler;

  /**
   * Entity type manager service.
   *
   * @var Drupal\Core\Entity\EntityTypeManager
   */
  protected EntityTypeManager $entity_type_manager;

  /**
   * The test drupal connection.
   *
   * @var Drupal\pgsql\Driver\Database\pgsql\Connection
   */
  protected Connection $drupal_connection;

  /**
   * The test chado connection.
   *
   * @var Drupal\tripal_chado\Database\ChadoConnection
   */
  protected ChadoConnection $chado_connection;

  /**
   * The YAML file indicating the scenarios to test and how to setup the enviro.
   *
   * @var string
   */
  protected string $yaml_info_file = __DIR__ . '/TripalAnalysisBlastRebuildServiceTest-TestInfo.yml';

  /**
   * Describes the environment to setup for this test.
   *
   * @var array
   *   Not used by this test, but here for consistency with other tests.
   */
  protected array $system_under_test;

  /**
   * Describes the scenarios to test.
   *
   * This will be used in combination with the data provider. It can't be
   * accessed directly in the dataProvider due to the way that PHPUnit is
   * setup.
   *
   * @var array
   *  A list of scenarios where each one has the following keys:
   *  - label: A human-readable label for the scenario to be used in assert
   *    messages.
   *  - expected_modules: the list of modules that we expect to have
   *    implemented the rebuild hook.
   *  - expected_config_entities: the list of Tripal Layout configuration
   *    entities that we expect to be rebuilt/created when the hook is run.
   *    Each item may have the following keys:
   *    - module: the module implementing the config entity.
   *    - type: the type of configuration entity.
   *    - name: the unique name of the configuration entity.
   *    - class: the class implementing the configuration entity.
   */
  protected array $scenarios;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Initialize services.
    $this->module_installer = $this->container->get('module_installer');
    $this->module_handler = $this->container->get('module_handler');
    $this->entity_type_manager = $this->container->get('entity_type.manager');
    $this->drupal_connection = $this->container->get('database');
    $this->chado_connection = $this->createTestSchema(ChadoTestKernelBase::INIT_CHADO_EMPTY);
    $this->installSchema('user', ['users_data']);
    $this->installSchema('tripal_chado', ['tripal_custom_tables']);
    $this->installSchema('tripal_analysis_blast', ['tripal_analysis_blast']);

    // First retrieve info from the YAML file for this particular test.
    [$this->system_under_test, $this->scenarios] = $this->getTestInfoFromYaml($this->yaml_info_file);
  }

  /**
   * Tests the rebuild service.
   *
   * The rebuild service is called by hook_rebuild in any modules
   * that implement it, so we call it that way.
   */
  public function testTripalAnalysisBlastRebuild() {
    $current_scenario = $this->getYamlScenario(0, 'Unaltered call to TripalAnalysisBlast Rebuild service');

    // Not directly related to rebuild, but confirm that the drupal
    // table for this module exists.
    foreach ($current_scenario['expected_drupal_tables'] as $table) {
      $exists = $this->drupal_connection->schema()->tableExists($table['name']);
      $this->assertTrue($exists, 'The drupal table "' . $table['name'] . '" does not exist but we expect it to be created on module install');
    }

    // Tests that before rebuild the configuration entities do not exist.
    foreach ($current_scenario['expected_config_entities'] as $expect) {
      $exists = $this->checkConfigurationEntity($expect['type'], $expect['name']);
      $this->assertFalse($exists, 'The configuration for "' . $expect['type'] . ':' . $expect['name'] . '" from the "' . $expect['module'] . '" module should not exist');
    }

    // Tests that before rebuild the chado custom tables do not exist.
    foreach ($current_scenario['expected_chado_tables'] as $expect) {
      $this->checkChadoTableStatus($expect['name'], $expect['before']['exists'], ($expect['before']['columns'] ?? []));
    }

    // Execute hook_rebuild.
    // Find and execute all hook_rebuild instances in any installed modules.
    // Tripal rebuild hooks return the module name as a return value.
    $results = $this->module_handler->invokeAll('rebuild');
    foreach ($current_scenario['expected_modules'] as $module) {
      $this->assertContains($module, $results, "The rebuild hook for module \"$module\" was not invoked by invokeAll");
    }

    // Tests that rebuild created configuration entities.
    foreach ($current_scenario['expected_config_entities'] as $expect) {
      $exists = $this->checkConfigurationEntity($expect['type'], $expect['name']);
      $this->assertTrue($exists, 'The configuration for "' . $expect['type'] . ':' . $expect['name'] . '" from the "' . $expect['module'] . '" module should have been created');
    }

    // Tests that rebuild creates custom tables.
    // After rebuild they will exist.
    foreach ($current_scenario['expected_chado_tables'] as $expect) {
      $this->checkChadoTableStatus($expect['name'], $expect['after']['exists'], ($expect['after']['columns'] ?? []));
    }

    // Call the uninstall and verify that the chado tables are dropped.
    $this->module_installer->uninstall(['tripal_analysis_blast']);
    foreach ($current_scenario['expected_chado_tables'] as $expect) {
      $this->checkChadoTableStatus($expect['name'], FALSE, []);
    }

    // Check that drupal tables are dropped.
    foreach ($current_scenario['expected_drupal_tables'] as $table) {
      $exists = $this->drupal_connection->schema()->tableExists($table['name']);
      $this->assertFalse($exists, 'The drupal table "' . $table['name'] . '" exists but we expect it to be dropped on module uninstall');
    }
  }

  /**
   * Helper function to check existence of a configuration entity.
   *
   * @param string $config_type
   *   The type of configuration entity, e.g. "tripalfield_collection".
   * @param string $config_name
   *   The specific configuration, e.g. "general_chado".
   *
   * @return bool
   *   TRUE if it exists, FALSE if not.
   */
  protected function checkConfigurationEntity(string $config_type, string $config_name): bool {
    $exists = FALSE;
    $storage = $this->entity_type_manager->getStorage($config_type);
    $entity = $storage->load($config_name);
    if ($entity) {
      $exists = TRUE;
    }
    return $exists;
  }

  /**
   * Helper function to check whether columns exist in a chado table.
   *
   * @param string $table_name
   *   The name of the chado table.
   * @param bool $expect_exists
   *   TRUE if we expect that the table exists, FALSE if not.
   * @param array $expected_columns
   *   If the table exists, an array of expected columns. The array key
   *   is the column name, the value is a boolean for whether we expect
   *   it to exist or not (This is always TRUE here).
   *
   * @return void
   *   No return value.
   */
  protected function checkChadoTableStatus(string $table_name, bool $expect_exists, array $expected_columns): void {
    $args = [
      'format' => 'drupal',
      'source' => 'database',
      'clear' => TRUE,
    ];
    $table_exists = $this->chado_connection->schema()->tableExists($table_name);
    if ($expect_exists) {
      $this->assertTrue($table_exists, 'Table "' . $table_name . '" does not exist but we expect it to exist.');
      $table_schema = $this->chado_connection->schema()->getTableDef($table_name, $args);
      foreach ($expected_columns as $column => $state) {
        $this->assertEquals($state, array_key_exists($column, $table_schema['fields']),
          'Presence of column "' . $column . '" in table ' . $table_name . ' does not match expectation.');
      }
    }
    else {
      $this->assertFalse($table_exists, 'Table "' . $table_name . '" exists but we do not expect it to exist.');
    }
  }

}
