<?php

namespace Drupal\Tests\tripal_analysis_blast\Kernel\TripalImporter;

use Drupal\Core\Form\FormState;
use Drupal\Tests\tripal_chado\Kernel\ChadoTestKernelBase;
use Drupal\Tests\tripal_analysis_blast\Traits\TripalCultivateImporterTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tripal\Services\TripalLogger;
use Drupal\tripal_chado\Database\ChadoConnection;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the form + form-related functionality of the Blast XML Importer.
 */
#[Group('tripal-importer')]
#[Group('tripal-analysis-blast')]
#[RunTestsInSeparateProcesses]
class BlastXmlImporterFormTest extends ChadoTestKernelBase {

  use UserCreationTrait;
  use TripalCultivateImporterTestTrait;

  /**
   * Theme used in the test environment.
   *
   * @var string
   */
  protected string $defaultTheme = 'stark';

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'tripal',
    'tripal_chado',
    'tripal_layout',
    'tripal_analysis_blast',
  ];

  /**
   * A Database query interface for querying Chado using Tripal DBX.
   *
   * @var \Drupal\tripal_chado\Database\ChadoConnection
   */
  protected ChadoConnection $chado_connection;

  /**
   * A chado database for testing.
   *
   * @var int
   */
  protected int $db_id;

  /**
   * A chado analysis for testing.
   *
   * @var int
   */
  protected int $analysis_id;

  /**
   * A chado organism for testing.
   *
   * @var int
   */
  protected int $organism_id;

  /**
   * The cvterm for 'EDAM:Protein sequence'.
   *
   * @var int
   */
  protected int $protein_cvterm_id;

  /**
   * Saves details regarding the config.
   *
   * @var array
   */
  protected array $cvdbon;

  /**
   * The terms required by this module mapped to the cvterm_ids they are set to.
   *
   * @var array
   */
  protected array $terms;

  /**
   * A default listing of annotations associated with our importer.
   *
   * @var array
   */
  protected array $definitions = [
    'test_blast_importer' => [
      'id' => 'tripal-analysis-blast-xml-importer',
      'label' => 'Chado BLAST XML results loader',
      'description' => 'Import a BLAST XML file into Chado',
      'file_types' => ['xml'],
      'use_analysis' => TRUE,
      'require_analysis' => TRUE,
      'upload_title' => 'Phenotypic Trait Data File*',
      'upload_description' => 'This should not be visible!',
      'button_text' => 'Import Blast XML file',
      'file_upload' => TRUE,
      'file_local' => TRUE,
      'file_remote' => TRUE,
      'file_required' => TRUE,
    ],
  ];

  /**
   * The path to the tripal_analysis_blast module.
   *
   * @var string
   */
  private $module_path;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Ensure we see all logging in tests.
    \Drupal::state()->set('is_a_test_environment', TRUE);

    // Open connection to Chado.
    $this->chado_connection = $this->getTestSchema(ChadoTestKernelBase::PREPARE_TEST_CHADO);

    // Ensure we can access file_managed related functionality from Drupal.
    // ... users need access to system.action config?
    $this->installConfig(['system', 'tripal_analysis_blast']);
    // ... managed files are associated with a user.
    $this->installEntitySchema('user');
    // ... Finally the file module + tables itself.
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('tripal_chado', ['tripal_custom_tables']);
    // Ensure we have our tripal import tables.
    $this->installSchema('tripal', ['tripal_import', 'tripal_jobs']);
    // Create and log-in a user.
    $this->setUpCurrentUser();

    // We need to mock the logger to test the progress reporting.
    $container = \Drupal::getContainer();
    $mock_logger = $this->getMockBuilder(TripalLogger::class)
      ->onlyMethods(['error'])
      ->getMock();
    $mock_logger->method('error')
      ->willReturnCallback(function ($message, $context, $options) {
        // @todo Revisit print out of log messages, but perhaps setting an option
        // for log messages to not print to the UI?
        // print str_replace(array_keys($context), $context, $message);
        return NULL;
      });

    $container->set('tripal.logger', $mock_logger);

    $this->module_path = $this->container->get('module_handler')
      ->getModule('tripal_analysis_blast')
      ->getPath();

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

    // Create a test analysis.
    $this->analysis_id = $this->chado_connection->insert('1:analysis')
      ->fields([
        'name' => 'Protein blast to Swiss-Prot and TrEMBL release 2026_03',
        'program' => 'blastp',
        'programversion' => '2.14.0+',
        'algorithm' => 'BLAST',
      ])
      ->execute();
    $this->assertIsNumeric($this->analysis_id,
      'We were not able to create an analysis for testing.');

    // Create a test organism.
    $this->organism_id = $this->chado_connection->insert('1:organism')
      ->fields([
        'genus' => 'Daucus',
        'species' => 'carota',
      ])
      ->execute();
    $this->assertIsNumeric($this->organism_id,
      'We were not able to create an organism for testing.');

    // Look up EDAM:Protein sequence.
    $this->protein_cvterm_id = $this->chado_connection->select('1:cvterm', 'T')
      ->condition('T.name', 'Protein Sequence', '=')
      ->fields('T', ['cvterm_id'])
      ->execute()->fetchField();
    $this->assertIsNumeric($this->protein_cvterm_id,
      'We were not able to find the "Protein Sequence" cvterm.');
  }

  /**
   * Tests building the importer form when all should be well.
   */
  public function testTraitImporterFormValid() {

    $plugin_id = 'blast_importer';
    $importer_label = 'Chado BLAST XML results loader';

    // Build the form using the Drupal form builder.
    $form = \Drupal::formBuilder()->getForm(
      'Drupal\tripal\Form\TripalImporterForm',
      $plugin_id
    );
    // Ensure we are able to build the form.
    $this->assertIsArray($form,
      'We expect the form builder to return a form but it did not.');
    $this->assertEquals('tripal_admin_form_tripalimporter', $form['#form_id'],
      'We did not get the form id we expected.');

    // We also expect the full form to be rendered, so check that now.
    // Now that we have provided a plugin_id, we expect it to have
    // a title matching our importer label.
    $this->assertArrayHasKey('#title', $form,
      'The form should have a title set.');
    $this->assertEquals($importer_label, $form['#title'],
      'The title should match the label annotated for our plugin.');
    // The plugin_id stored in a value form element.
    $this->assertArrayHasKey('importer_plugin_id', $form,
      'The form should have an element to save the plugin_id.');
    $this->assertEquals($plugin_id, $form['importer_plugin_id']['#value'],
      'The importer_plugin_id[#value] should be set to our plugin_id.');

    // Check the file fieldset contents.
    $this->assertArrayHasKey('file', $form,
      'We expect there to be a file fieldset but there is not.');
    $this->assertEquals('fieldset', $form['file']['#type'],
      'We expect the file element in the form to be a fieldset.');
    // We expect there to be an upload description.
    $this->assertArrayHasKey('upload_description', $form['file'],
      'We expect the upload description to have been added to the form by the TripalImporter base class.');
    // We expect the file upload HTML5 element provided by Tripal
    // as well as the file local/remote.
    $this->assertArrayHasKey('file_upload', $form['file'],
      'We expect the file upload element to be added by the Tripal Importer base class.');
    $this->assertArrayHasKey('file_local', $form['file'],
      'The local file element should be available.');
    $this->assertArrayHasKey('file_remote', $form['file'],
      'The remote file element should be available.');

    // Check the various top-level form elements.
    $expected = [
      'blastdb_id' => 'select',
      'blastfile_ext' => 'textfield',
      'no_parsed' => 'number',
      'query_re' => 'textfield',
      'query_type' => 'textfield',
      'query_uniquename' => 'checkbox',
      'advanced' => NULL,
    ];
    foreach ($expected as $id => $type) {
      $this->assertArrayHasKey($id, $form,
        'We expect there to be a "' . $id . '" form element but there is not.');
      if ($type) {
        $this->assertEquals($type, $form[$id]['#type'],
          'We expect the "' . $id . '" element in the form to be of type "' . $type . '".');
      }
    }

    // Check the various advanced form elements.
    $expected = [
      'debug' => 'checkbox',
      'schema_name' => 'select',
    ];
    foreach ($expected as $id => $type) {
      $this->assertArrayHasKey($id, $form['advanced'],
        'We expect there to be a "' . $id . '" form element but there is not.');
      $this->assertEquals($type, $form['advanced'][$id]['#type'],
        'We expect the "' . $id . '" element in the form to be of type "' . $type . '".');
    }
  }

  /**
   * Tests submitting the importer form without required fields.
   */
  public function testTraitImporterFormSubmitInvalid() {

    $plugin_id = 'blast_importer';

    // Create a file to upload.
    $file = $this->createTestFile([
      'filename' => '.xml',
      'content' => [
        'file' => 'dcarv3-vs-uniprot_2026_03.xml',
        'fixturepath' => $this->module_path . '/tests/fixtures/',
      ],
    ]);

    // Setup the form_state without the required fields.
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$plugin_id]);

    // Now try validation!
    \Drupal::formBuilder()->submitForm(
      'Drupal\tripal\Form\TripalImporterForm',
      $form_state
    );

    // Check that validation happened.
    $this->assertTrue($form_state->isValidationComplete(),
      'We expect the form state to have been updated to indicate that validation is complete.');

    // Looking for expected form validation errors.
    $expected = [
      'Analysis field is required',
      'Database field is required',
      'You must provide a file location or upload a file',
    ];
    $form_validation_messages = $form_state->getErrors();
    $helpful_output = [];
    foreach ($form_validation_messages as $element => $markup) {
      $helpful_output[] = $element . ' => ' . (string) $markup;
    }
    foreach ($expected as $text) {
      $this->assertStringContainsString($text, implode(' ', $helpful_output), 'We are missing an expected validation error');
    }
    $this->assertCount(3, $form_validation_messages,
      'We expected exactly three validation errors, but instead have: ' . implode(' AND ', $helpful_output));
  }

  /**
   * Tests submitting the importer form with only required fields.
   */
  public function testTraitImporterFormSubmitMinimalValid() {

    $plugin_id = 'blast_importer';

    // Create a file to upload.
    $file = $this->createTestFile([
      'filename' => '.xml',
      'content' => [
        'file' => 'dcarv3-vs-uniprot_2026_03.xml',
        'fixturepath' => $this->module_path . '/tests/fixtures/',
      ],
    ]);

    // Setup the form_state with only required fields.
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$plugin_id]);
    $form_state->setValue('blastdb_id', $this->db_id);
    $form_state->setValue('analysis_id', $this->analysis_id);
    $form_state->setValue('file_upload', $file->id());

    // Now try validation!
    \Drupal::formBuilder()->submitForm(
      'Drupal\tripal\Form\TripalImporterForm',
      $form_state
    );

    // Check that validation happened.
    $this->assertTrue($form_state->isValidationComplete(),
      'We expect the form state to have been updated to indicate that validation is complete.');

    // Looking for form validation errors, do not expect any.
    $form_validation_messages = $form_state->getErrors();
    $helpful_output = [];
    foreach ($form_validation_messages as $element => $markup) {
      $helpful_output[] = $element . ' => ' . (string) $markup;
    }
    $this->assertCount(0, $form_validation_messages,
      'We should not have any errors but instead we have: ' . implode(' AND ', $helpful_output));
  }

  /**
   * Tests submitting the importer form with all fields.
   */
  public function testTraitImporterFormSubmitAllFieldsValid() {

    $plugin_id = 'blast_importer';

    // Create a file to upload.
    $file = $this->createTestFile([
      'filename' => '.xml',
      'content' => [
        'file' => 'dcarv3-vs-uniprot_2026_03.xml',
        'fixturepath' => $this->module_path . '/tests/fixtures/',
      ],
    ]);

    // Setup the form_state with all fields.
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$plugin_id]);
    $form_state->setValue('blastdb_id', $this->db_id);
    $form_state->setValue('analysis_id', $this->analysis_id);
    $form_state->setValue('file_upload', $file->id());
    $form_state->setValue('blastfile_ext', 'xml');
    $form_state->setValue('no_parsed', '10');
    $form_state->setValue('query_re', '/^(\S+)/');
    $form_state->setValue('query_type', $this->protein_cvterm_id);
    $form_state->setValue('query_uniquename', 1);

    // Now try validation!
    \Drupal::formBuilder()->submitForm(
      'Drupal\tripal\Form\TripalImporterForm',
      $form_state
    );

    // Check that validation happened.
    $this->assertTrue($form_state->isValidationComplete(),
      'We expect the form state to have been updated to indicate that validation is complete.');

    // Looking for form validation errors, do not expect any.
    $form_validation_messages = $form_state->getErrors();
    $helpful_output = [];
    foreach ($form_validation_messages as $element => $markup) {
      $helpful_output[] = $element . ' => ' . (string) $markup;
    }
    $this->assertCount(0, $form_validation_messages,
      'We should not have any errors but instead we have: ' . implode(' AND ', $helpful_output));
  }

}
