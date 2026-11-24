<?php

namespace Drupal\Tests\tripal_analysis_blast\Kernel\TripalImporter;

use Drupal\Tests\tripal_chado\Kernel\ChadoTestKernelBase;
use Drupal\Tests\tripal_analysis_blast\Traits\TripalCultivateImporterTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tripal_analysis_blast\Plugin\TripalImporter\BlastImporter;
#use Drupal\tripal_analysis_blast\Plugin\TripalImporter;
use Drupal\tripal\Services\TripalLogger;
use Drupal\tripal_chado\Database\ChadoConnection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the functionality of the run() method of the Traits Importer.
 */
#[Group('traipal-analysis-blast')]
#[RunTestsInSeparateProcesses]
class BlastXmlImporterRunTest extends ChadoTestKernelBase {

  use UserCreationTrait;
  use TripalCultivateImporterTestTrait;

  /**
   * Theme used in the test environment.
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

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
   * Our instance of the Blast Importer for testing.
   *
   * @var Drupal\tripal_analysis_blast\Plugin\TripalImporter\BlastImporter
   */
  protected BlastImporter $importer;

  /**
   * The most recent messages from the mocked tripal logger.
   *
   * @var string
   */
  protected string $logger_output = '';

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
      'upload_title' => 'XML FILE',
      'upload_description' => 'Please provide the XML file.',
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

    // Install dependencies.
    $this->installConfig([
      'system',
      'tripal_analysis_blast',
    ]);
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', [
      'file_usage',
    ]);
    $this->installSchema('tripal', [
      'tripal_id_space_collection',
      'tripal_import',
      'tripal_jobs',
      'tripal_vocabulary_collection',
    ]);
    $this->installSchema('tripal_chado', [
      'tripal_custom_tables',
    ]);

    // Install module tables.
    $this->installSchema('tripal_analysis_blast', [
      'tripal_analysis_blast',
    ]);
    \Drupal::service('tripal_analysis_blast.rebuild_service')->createCustomChadoTables();

    // Create and log-in a user.
    $this->setUpCurrentUser();

    // Grab the container.
    $container = \Drupal::getContainer();

    // Create a mocked logger so we can access messages from the Tripal logger.
    $mock_logger = $this->getMockBuilder(TripalLogger::class)
      ->onlyMethods(['notice', 'warning', 'error'])
      ->getMock();
    $mock_logger->method('notice')
      ->willReturnCallback(function ($message, $context, $options) {
          $this->logger_output .= 'NOTICE: ' . str_replace(array_keys($context), $context, $message) . "\n";
          return NULL;
      });
    $mock_logger->method('warning')
      ->willReturnCallback(function ($message, $context, $options) {
          $this->logger_output .= 'WARNING: ' . str_replace(array_keys($context), $context, $message) . "\n";
          return NULL;
      });
    $mock_logger->method('error')
      ->willReturnCallback(function ($message, $context, $options) {
          $this->logger_output .= 'ERROR: ' . str_replace(array_keys($context), $context, $message) . "\n";
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
        'sourcename' => 'ExPASy',
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
      ->condition('T.name', 'polypeptide', '=')
      ->fields('T', ['cvterm_id'])
      ->execute()->fetchField();
    $this->assertIsNumeric($this->protein_cvterm_id,
      'We were not able to find the "polypeptide" cvterm.');

    // Create the terms defined by this module.
    $terms_init_service = \Drupal::service('tripal_chado.terms_init');
    $terms_init_service->installTerms('tripal_analysis_blast_content_terms');

    // Import some 'polypeptide' features.
    $this->createTestFeatures();

    // Initialize the importer being tested.
    $this->importer = new BlastImporter(
      [],
      'blast_importer',
      $this->definitions,
      $this->container->get('tripal_chado.chado_buddy'),
      $this->chado_connection,
      $this->container->get('messenger'),
      $container->get('tripal.logger'),
      $container->get('tripal.fileretriever'),
      $container->get('tripal.backend_publish'),
      $container->get('file.repository'),
      $container->get('file_system'),
    );
  }

  /**
   * Tests the run() function using an actual XML file.
   */
  public function testBlastImporterRunSimple() {

    $file = $this->createTestFile([
      'filename' => 'dcarv3-vs-uniprot_2026_03.xml',
      'content' => [
        'file' => 'dcarv3-vs-uniprot_2026_03.xml',
        'fixturepath' => $this->module_path . '/tests/fixtures/',
      ],
    ]);

    $run_args = [
      'analysis_id' => $this->analysis_id,
      'blastdb_id' => $this->db_id,
      'files' => [0 => ['file_path' => '']],
      'blastfile_ext' => '',
      'no_parsed' => 5,
      'query_re' => '',
      'query_type' => '',
      'query_uniquename' => 0,
    ];
    $file_details = ['fid' => $file->id()];

    $this->importer->createImportJob($run_args, $file_details);
    $this->importer->prepareFiles();
    $this->importer->run();
    $this->importer->postRun();

    // Verify logger messages.
    $this->assertStringContainsString('Parsing file', $this->logger_output, 'Did not see expected logger message');
    $this->assertStringContainsString('Percent complete: 100.00', $this->logger_output, 'Did not see expected logger message');
  }

  /**
   * Populates some polypeptides in the feature table.
   *
   * @return void
   *   No return value.
   */
  protected function createTestFeatures(): void {
    $sequences = [
      'DCAR_100001' => 'MSSQKDFNDDSQVSGGPSQPAPRKDLHTKVEGRGSRIRMPTRCAARVFQLTKELGLATDGETIQWLLQQAELAIIRATGTGTVPATATVTADGSLRVPETKEGAAEDVSKTSGLAPVGPSTVSVPGYGMAAENGMKMNQNQTSVSCEARASSAEEEGYDETVLMGKKIRFRKGGI',
      'DCAR_100002' => 'MSSQKDFKGDSQVSGGPSQPAPRKDLHTKVEGRGSRIRMPTLCATRVFQLTKELGLATDGETIQWLLQLLMGPSESRRRKRELLRMFPRPRDLRLWAPAPCLCQGMGWRRKME',
      'DCAR_100003' => 'MGIVRLLPSVRPCQFLEKLSKTDKQLSFILHLDVFIQQNEAAYELEEASEARDRQKQQPVSEIVELDKPVNTDEPQNKEDGGTVKSEMEFRGLDHADSLGLILISCRAKYLVNWLVNALMSLNHPNSENALPLVTIYGPKVYDLIEGQQWVDPALVQKLADRNNISLSCGFLKNIYFSDMQDNERNLLESKNPEQAGPLRNKKDKCILGISVITVSLGFLTNFEDVCRLWEFASKFLDADFVEKEKWRYLALNQTTVEGVRHALHSLKKKTYEVSHIVSSLLND',
      'DCAR_100004' => 'MPLSNKKLSLILAFINFFLLACSEQLNASSGIPPVAYQAKALLAWKAGFDNQSQSVVASWKGNSPCSKWIGISCSRGGNVIRISLTSYGLRGKLHDLNFSSLPNLLKIDLFNNSIYGTIPQEIGKLKYLMYLELARNGLTGKIPDSVGDLRNLRVIQLYENQLSGHIPPTLGNLTMLDSLFLHLNFLSGSIPSELGKLKNLAYLRLSSNNLTGTLPLEFNNLTRLVAFRMSENLLTGPLPDNICAGGLLEKFTVPNNRFTGPVPRSLKNCTSLYRLRLDGNQLTGDISEAFGVYPHLNYVDLSRNKFYGKVSSNWGLCKNLTSLRISDNNMSGQISPELGKATLLVELDLSRNHLVGGIPNSLANLASLLELLLHDNKLSGDIPPDIAKLPNLANLNLGNNNLSGLIPKNLAMCRHLLNLNLSQNCIRGRIPSEIGSLQSLQYLDLSWNLLTGEVPKVLGGLRSLETLNISHNELNGSIKSTFELMQSLISVDISYNQLEGPLPDTKAFREAPVAALEKNKGLCGNIIGLDKCPGIQHDEGKQKQHRKLFILILLSLLAVPIFLYISALIVYYLHHRARNLKTKATIEDTNLFTIWSYDSKIVYEEIVRATDNFNADHCIGTGGSGSVYKAHFPSGQNWAIKRLHAPEDEMLPDLKSFTNEIRTLTSIKHRNIVKLYGFCSKPQQSFLIYEFLERGSLSNILNNQKIAANFEWIQRLNVIKDVVSGLSYMHHDCKPSIIHRDLSSKNILLDSNSVAHISDFGTARFLKPNSSNWTSFAGTYGYSAPELAYTMNVDEKCDVYSFGILAMEVIMGRHPGDLVSSLSTSSARSFQSSVDETLVKDVVDQRLPSPKTHVAEEMVYIIKIALACLHLIPQCRPTFRQVSLQLSKRRPPLQTAFHMITLSQLLDPRRDQSLH',
      'DCAR_100006' => 'MSRLFTEREKQVINHLLPEEIEEFLGNPTRSVRSFFSDRWSELHLGSNPTERSTRDHKLLKKQQDLSFVPSRRSEKKEMVNIFKIITYLQNTVSIHPISSYPGCDMVPKDEPDMDSSNKISFLNKNPFLDLFHLFHDRNMGGYTLHHDFESEERFQEMADLFTLSITEPDLVYHKGFAFSIDSYGLDQKQFLNEVFNSRDESKKKSLLALPPFFYEENESFYRRIIKKWVRISCGNDLEDPKPKKMVFASNNLNVLNRFFLMNRSDRNFEYGIQRDQIGKDTLNHRTRMKYMINQHLSNLKKSQKRWFDPLILISRTERSTNRDPDAYRYKWSNGSNNFQEHLDHFVSERKSRFRVVFDRLRINQYSIDWSEVIDKKGLSKPFRFFLSKSLLFCPSHFFFVQVSFLFV',
    ];
    foreach ($sequences as $id => $sequence) {
      $feature_id = $this->chado_connection->insert('1:feature')
        ->fields([
          'organism_id' => $this->organism_id,
          'name' => $id,
          'uniquename' => $id . '.1',
          'residues' => $sequence,
          'seqlen' => strlen($sequence),
          'type_id' => $this->protein_cvterm_id,
        ])
        ->execute();
      $this->assertIsNumeric($feature_id, 'Expect a numeric feature_id after insert');
    }
  }

}
