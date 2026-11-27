<?php

namespace Drupal\Tests\tripal_analysis_blast\Kernel\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Tests\tripal_chado\Kernel\ChadoTestKernelBase;
use Drupal\tripal_analysis_blast\Form\TripalAnalysisBlastConfigurationForm;
use Drupal\tripal_chado\Database\ChadoConnection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the tripal analysis blast configuration form.
 */
#[Group('tripal-analysis-blast')]
#[RunTestsInSeparateProcesses]
class TripalAnalysisBlastConfigurationFormTest extends ChadoTestKernelBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'tripal', 'tripal_chado', 'tripal_analysis_blast'];

  /**
   * Stores the route to the form being testes.
   *
   * @var string
   */
  protected string $form_route = 'Drupal\tripal_analysis_blast\Form\TripalAnalysisBlastConfigurationForm';

  /**
   * The drupal database connection.
   *
   * @var Drupal\Core\Database\Connection
   */
  protected Connection $drupal_connection;

  /**
   * A Database query interface for querying Chado using Tripal DBX.
   *
   * @var \Drupal\tripal_chado\Database\ChadoConnection
   */
  protected ChadoConnection $chado_connection;

  /**
   * The Drupal form builder service.
   *
   * @var Drupal\Core\Form\FormBuilder
   */
  protected FormBuilder $form_builder;

  /**
   * A chado database for testing.
   *
   * @var int
   */
  protected int $db_id;

  /**
   * A chado organism for testing.
   *
   * @var int
   */
  protected int $organism_id;

  /**
   * Translates from form keys to tripal_analysis_blast table columns.
   *
   * @var array
   */
  protected array $form_to_db_translation = [
    'database' => 'db_id',
    'hit_id' => 'regex_hit_id',
    'hit_def' => 'regex_hit_def',
    'hit_accession' => 'regex_hit_accession',
    'hit_organism_re' => 'regex_hit_organism',
    'organism_id' => 'hit_organism',
    'gb_style_parser' => 'genbank_style',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();

    // Ensure we see all logging in tests.
    \Drupal::state()->set('is_a_test_environment', TRUE);

    // Install this module's public table.
    $this->installConfig(['system', 'tripal_analysis_blast']);
    $this->installSchema('tripal_analysis_blast', ['tripal_analysis_blast']);
    $this->assertTrue(\Drupal::database()->schema()->tableExists('tripal_analysis_blast'),
      'Did not install public.tripal_analysis_blast table');

    // The Drupal form builder service.
    $this->form_builder = \Drupal::formBuilder();

    // Open connection to the Drupal database.
    $this->drupal_connection = \Drupal::database();

    // Open connection to Chado.
    $this->chado_connection = $this->getTestSchema(ChadoTestKernelBase::PREPARE_TEST_CHADO);

    // Create a test database.
    $this->db_id = $this->chado_connection->insert('1:db')
      ->fields([
        'name' => 'ExPASy Swiss-Prot and TrEMBL',
        'description' => 'Combined protein sequences from ExPASy Swiss-Prot and ExPASy TrEMBL databases',
        'url' => 'https://expasy.org/',
        'urlprefix' => 'https://www.uniprot.org/uniprot/{accession}',
      ])
      ->execute();
    $this->assertIsNumeric($this->db_id,
      'We were not able to create a database for testing.');

    // Create a test organism.
    $this->organism_id = $this->chado_connection->insert('1:organism')
      ->fields([
        'genus' => 'Tripalus',
        'species' => 'bogusii',
      ])
      ->execute();
    $this->assertIsNumeric($this->organism_id,
      'We were not able to create an organism for testing.');
  }

  /**
   * Tests the Tripal Entity Settings form.
   *
   * @return void
   *   No return value.
   */
  public function testTripalAnalysisBlastConfigurationForm() {
    $form_object = TripalAnalysisBlastConfigurationForm::create($this->container);
    $form_state = new FormState();

    /** CASE 1: No database specified */
    // Build the form without passing any input values.
    $form = $form_object->buildForm([], $form_state);

    // Ensure we are able to build the form.
    $this->assertIsArray($form,
      'We expect the form builder to return a form but it did not.');
    $form_id = $form_object->getFormId();
    $this->assertEquals('tripal_analysis_blast_configuration', $form_id,
      'We did not get the form id we expected.');

    // Confirm we have a database select element. This will be here
    // even before ajax fires. Also the container for the database
    // settings is always present.
    // Without ajax triggered, the remaining fields should be missing.
    $base_expectations = [
      'database' => 'select',
      'database_settings' => 'container',
    ];
    $this->validateFormElements($form['databases'], $base_expectations);

    $element_type = $form['databases']['database_settings']['#type'] ?? NULL;
    $this->assertEquals('container', $element_type,
      'Missing a container for the database settings');

    // Without ajax triggered, the remaining fields should be missing.
    $expectations = [
      'database_genbank_style' => '',
    ];
    $this->validateFormElements($form['databases']['database_settings'], $expectations);

    // Confirm we have a submit button.
    $expectations = [
      'submit' => 'submit',
    ];
    $this->validateFormElements($form['actions'], $expectations);

    // Call the form validation with no database selected.
    // Expect one validation error, database not specified.
    $form_object->validateForm($form, $form_state);
    $form_validation_messages = $form_state->getErrors();
    $this->assertCount(1, $form_validation_messages,
      'We expect exactly one validation error when database is not specified.');
    $this->assertStringContainsString('A database was not selected', $form_validation_messages['database'] ?? NULL,
      'Did not receive the expected validation error message');

    /** CASE 2: Database specified, genbank style not checked */
    // Generate the form again with a database specified.
    $form_state = new FormState();
    $form_state->setValues([
      'database' => $this->db_id,
    ]);
    $form = $form_object->buildForm([], $form_state);

    // No validation error expected when database is specified.
    $form_object->validateForm($form, $form_state);
    $form_validation_messages = $form_state->getErrors();
    $this->assertEmpty($form_validation_messages,
      'Do not expect any validation errors for valid database');

    // Validate expected form elements that are always there.
    $this->validateFormElements($form['databases'], $base_expectations);

    // Validate expected form fields in the database_settings container.
    $db_expectations = [
      'urlprefix' => 'textfield',
      'displayname' => 'textfield',
      'gb_style_parser' => 'checkbox',
      'database_genbank_style' => 'container',
    ];
    $this->validateFormElements($form['databases']['database_settings'], $db_expectations);

    // Validate expected form fields in the database_genbank_style container.
    $genbank_expectations = [
      'hit_id' => 'textfield',
      'hit_def' => 'textfield',
      'hit_accession' => 'textfield',
      'hit_organism_re' => 'textfield',
      'organism_id' => 'select',
    ];
    $this->validateFormElements($form['databases']['database_settings']['database_genbank_style'], $genbank_expectations);

    /** CASE 3: Database specified, genbank style is checked */
    // Triggering the genbank_style ajax should remove the fields.
    $form_state = new FormState();
    $form_state->setValues([
      'database' => $this->db_id,
      'gb_style_parser' => 1,
    ]);
    $form = $form_object->buildForm([], $form_state);

    // No validation error expected when database is specified.
    $form_object->validateForm($form, $form_state);
    $form_validation_messages = $form_state->getErrors();
    $this->assertEmpty($form_validation_messages,
      'Do not expect any validation errors for valid database');

    // Validate expected form elements that are always there.
    $this->validateFormElements($form['databases'], $base_expectations);

    // Validate expected form fields in the database_settings container.
    $this->validateFormElements($form['databases']['database_settings'], $db_expectations);

    // Validate form fields that should now not be present.
    $override_expectations = [
      'hit_id' => '',
      'hit_def' => '',
      'hit_accession' => '',
      'hit_organism_re' => '',
      'organism_id' => '',
    ];
    $this->validateFormElements($form['databases']['database_settings']['database_genbank_style'], $override_expectations);

    /** CASE 4: Invalid values caught by validation. */
    // Generate the form again with a database specified.
    $form_state = new FormState();
    $form_state->setValues([
      'database' => $this->db_id,
      'hit_id' => '/invalidregex1',
      'hit_def' => '/invalidregex2',
      'hit_accession' => '/invalidregex3',
      'hit_organism_re' => '/invalidregex4',
      'organism_id' => $this->organism_id,
    ]);
    $form = $form_object->buildForm([], $form_state);

    // No validation error expected when database is specified.
    $form_object->validateForm($form, $form_state);
    $form_validation_messages = $form_state->getErrors();
    $fields = ['hit_id', 'hit_def', 'hit_accession', 'hit_organism_re'];
    foreach ($fields as $field) {
      $this->assertArrayHasKey($field, $form_validation_messages,
        'We expect a validation error for this field');
      $this->assertStringContainsString('The regular expression is not valid', $form_validation_messages[$field],
        'We expect an error for an invalid regular expression');
    }

    // The validation for specifying both organism re and organism id.
    $this->assertArrayHasKey('organism_id', $form_validation_messages,
      'We expect a validation error for this field');
    $this->assertStringContainsString('Do not set both "Regular expression for Organism" and "Organism" at the same time', $form_validation_messages['organism_id'],
      'We expect an error for specifying both fields at the same time');

    // Validate expected form elements that are always there.
    $this->validateFormElements($form['databases'], $base_expectations);

    // Validate expected form fields in the database_settings container.
    $this->validateFormElements($form['databases']['database_settings'], $db_expectations);

    // Validate expected form fields in the database_genbank_style container.
    $this->validateFormElements($form['databases']['database_settings']['database_genbank_style'], $genbank_expectations);

    /** CASE 5: Regular expression are valid but do not contain parentheses. */
    // Generate the form again with a database specified.
    $form_state = new FormState();
    $form_state->setValues([
      'database' => $this->db_id,
      'hit_id' => '/^validregex1.*/',
      'hit_def' => '/^validregex2.*/',
      'hit_accession' => '/^validregex3.*/',
      'hit_organism_re' => '/^validregex4.*/',
    ]);
    $form = $form_object->buildForm([], $form_state);

    // No validation error expected when database is specified.
    $form_object->validateForm($form, $form_state);
    $form_validation_messages = $form_state->getErrors();
    $fields = ['hit_id', 'hit_def', 'hit_accession', 'hit_organism_re'];
    foreach ($fields as $field) {
      $this->assertArrayHasKey($field, $form_validation_messages,
        'We expect a validation error for this field');
      $this->assertStringContainsString('The regular expression must contain parentheses', $form_validation_messages[$field],
        'We expect an error for a regular expression without parentheses');
    }

    // Validate expected form elements that are always there.
    $this->validateFormElements($form['databases'], $base_expectations);

    // Validate expected form fields in the database_settings container.
    $this->validateFormElements($form['databases']['database_settings'], $db_expectations);

    // Validate expected form fields in the database_genbank_style container.
    $this->validateFormElements($form['databases']['database_settings']['database_genbank_style'], $genbank_expectations);

    /** CASE 6: All values are specified and are valid. */
    $form_state = new FormState();
    $form_values = [
      'database' => $this->db_id,
      'displayname' => 'A database',
      'gb_style_parser' => 0,
      'hit_id' => '/^1(\S+)/',
      'hit_def' => '/^2(\S+)/',
      'hit_accession' => '/^3(\S+)/',
      'hit_organism_re' => '/^4(\S+)/',
      'organism_id' => '',
    ];
    $form_state->setValues($form_values);
    $form = $form_object->buildForm([], $form_state);

    // No validation errors expected for this case.
    $form_object->validateForm($form, $form_state);
    $form_validation_messages = $form_state->getErrors();
    $this->assertEmpty($form_validation_messages,
      'Do not expect any validation errors for valid database.');

    // Validate expected form elements that are always there.
    $this->validateFormElements($form['databases'], $base_expectations);

    // Validate expected form fields in the database_settings container.
    $this->validateFormElements($form['databases']['database_settings'], $db_expectations);

    // Validate expected form fields in the database_genbank_style container.
    $this->validateFormElements($form['databases']['database_settings']['database_genbank_style'], $genbank_expectations);

    // Submit the form.
    $form_object->submitForm($form, $form_state);

    // Validate that values are stored in the database table.
    $query = $this->drupal_connection->select('tripal_analysis_blast', 'B');
    $query->condition('db_id', $this->db_id, '=');
    $query->fields('B', []);
    $result = $query->execute()->fetchAssoc();

    $this->assertIsArray($result,
      'Did not retrieve a record from the tripal_analysis_blast table matching db_id "' . $this->db_id . '"');
    foreach ($form_values as $key => $value) {
      $db_column = $this->form_to_db_translation[$key] ?? $key;
      $this->assertEquals($value, $result[$db_column],
        'Incorrect value stored in tripal_analysis_blast table in column "' . $db_column . '" from form key "' . $key . '"');
    }

    /** CASE 7: Update existing valid values. */
    $form_state = new FormState();
    $form_values = [
      'database' => $this->db_id,
      'displayname' => 'An updated database',
      'gb_style_parser' => 0,
      'hit_id' => '/^up1(\S+)/',
      'hit_def' => '/^up2(\S+)/',
      'hit_accession' => '/^up3(\S+)/',
      'hit_organism_re' => '/^up4(\S+)/',
      'organism_id' => '',
    ];
    $form_state->setValues($form_values);
    $form = $form_object->buildForm([], $form_state);

    // No validation errors expected for this case.
    $form_object->validateForm($form, $form_state);
    $form_validation_messages = $form_state->getErrors();
    $this->assertEmpty($form_validation_messages,
      'Do not expect any validation errors for valid database.');

    // Validate expected form elements that are always there.
    $this->validateFormElements($form['databases'], $base_expectations);

    // Validate expected form fields in the database_settings container.
    $this->validateFormElements($form['databases']['database_settings'], $db_expectations);

    // Validate expected form fields in the database_genbank_style container.
    $this->validateFormElements($form['databases']['database_settings']['database_genbank_style'], $genbank_expectations);

    // Submit the form.
    $form_object->submitForm($form, $form_state);

    // Validate that values are stored in the database table.
    $query = $this->drupal_connection->select('tripal_analysis_blast', 'B');
    $query->condition('db_id', $this->db_id, '=');
    $query->fields('B', []);
    $result = $query->execute()->fetchAssoc();

    $this->assertIsArray($result,
      'Did not retrieve a record from the tripal_analysis_blast table matching db_id "' . $this->db_id . '"');
    foreach ($form_values as $key => $value) {
      $db_column = $this->form_to_db_translation[$key] ?? $key;
      $this->assertEquals($value, $result[$db_column],
        'Incorrect value stored in tripal_analysis_blast table in column "' . $db_column . '" from form key "' . $key . '"');
    }

    /** CASE 8: Removing table record by supplying all empty values. */
    $form_state = new FormState();
    $form_values = [
      'database' => $this->db_id,
      'displayname' => '',
      'gb_style_parser' => 0,
      'hit_id' => '',
      'hit_def' => '',
      'hit_accession' => '',
      'hit_organism_re' => '',
      'organism_id' => '',
    ];
    $form_state->setValues($form_values);
    $form = $form_object->buildForm([], $form_state);

    // No validation errors expected for this case.
    $form_object->validateForm($form, $form_state);
    $form_validation_messages = $form_state->getErrors();
    $this->assertEmpty($form_validation_messages,
      'Do not expect any validation errors for valid database.');

    // Validate expected form elements that are always there.
    $this->validateFormElements($form['databases'], $base_expectations);

    // Validate expected form fields in the database_settings container.
    $this->validateFormElements($form['databases']['database_settings'], $db_expectations);

    // Validate expected form fields in the database_genbank_style container.
    $this->validateFormElements($form['databases']['database_settings']['database_genbank_style'], $genbank_expectations);

    // Submit the form.
    $form_object->submitForm($form, $form_state);

    // Validate that values are no longer stored in the database table.
    $query = $this->drupal_connection->select('tripal_analysis_blast', 'B');
    $query->condition('db_id', $this->db_id, '=');
    $query->fields('B', []);
    $count = $query->countQuery()->execute()->fetchField();

    $this->assertEquals(0, $count,
      'Expected the record in the tripal_analysis_blast table to be deleted but it was not.');

  }

  /**
   * Helper function to validate form element types.
   *
   * @param array $subform
   *   A part of the form to validate.
   * @param array $expectations
   *   Keys are form array keys, values are expected type.
   *   If value is empty, this means we expect to not see
   *   this array key in the form.
   */
  protected function validateFormElements(array $subform, array $expectations) {
    foreach ($expectations as $key => $type) {
      if ($type) {
        $this->assertArrayHasKey($key, $subform,
          'Missing expected form key "' . $key . '". Keys found: ' . print_r(array_keys($subform), TRUE));
        $element_type = $subform[$key]['#type'] ?? NULL;
        $this->assertEquals($type, $element_type,
          'Form element "' . $key . '" "#type" does not match expectation');
      }
      else {
        $this->assertArrayNotHasKey($key, $subform,
          'Did not expect this form key "' . $key . '" to be present');
      }
    }
  }

}
