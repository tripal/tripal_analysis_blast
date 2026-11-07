<?php

namespace Drupal\tripal_analysis_blast\Plugin\TripalImporter;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Link;
use Drupal\Core\Messenger\Messenger;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\file\FileRepositoryInterface;
use Drupal\tripal\TripalImporter\Attribute\TripalImporter;
#use Drupal\tripal\TripalImporter\Interfaces\TripalImporterInterface;
use Drupal\tripal\Services\TripalFileRetriever;
use Drupal\tripal\Services\TripalLogger;
use Drupal\tripal\TripalBackendPublish\PluginManager\TripalBackendPublishManager;
use Drupal\tripal_chado\ChadoBuddy\PluginManagers\ChadoBuddyPluginManager;
use Drupal\tripal_chado\Controller\ChadoCVTermAutocompleteController;
#use Drupal\tripal_chado\Controller\ChadoGenericAutocompleteController;
#use Drupal\tripal_chado\Controller\ChadoOrganismFormElementController;
use Drupal\tripal_chado\Database\ChadoConnection;
use Drupal\tripal_chado\TripalImporter\ChadoImporterBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
#use Symfony\Component\Serializer\Serializer;

/**
 * Blast result importer implementation of the TripalImporterBase.
 */
#[TripalImporter(
  id: 'blast_importer',
  label: new TranslatableMarkup('Chado BLAST XML results loader'),
  description: new TranslatableMarkup('Import a BLAST XML file into Chado'),
  file_types: [
    'xml',
    'gz',
  ],
  upload_description: new TranslatableMarkup('Please provide the XML file.'),
  upload_title: new TranslatableMarkup('XML FILE'),
  use_analysis: TRUE,
  require_analysis: TRUE,
  button_text: new TranslatableMarkup('Import Blast file'),
  file_upload: TRUE,
  file_local: TRUE,
  file_remote: TRUE,
  file_required: TRUE,
  publish: [
    'bundle' => [],
  ],
)]
class BlastImporter extends ChadoImporterBase {

  use StringTranslationTrait;

  /**
   * Used to store the manager so we can create various buddies.
   */
  protected object $buddy_manager;

  /**
   * Provide the dbxref buddy instance.
   */
  protected object $dbxref_buddy;

  /**
   * Provide the cvterm buddy instance.
   */
  protected object $cvterm_buddy;

  /**
   * @var Drupal\file\FileRepositoryInterface
   */
  protected FileRepositoryInterface $file_repository;

  /**
   * @var Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $file_system;

  /**
   * Temporary files used during import.
   *
   * @var array
   */
  protected array $temp_files = [];

  /**
   * Implements ContainerFactoryPluginInterface->create().
   *
   * We are injecting an additional dependency here, the
   * ChadoBuddyPluginManager.
   *
   * Since we have implemented the ContainerFactoryPluginInterface this static
   * function will be called behind the scenes when a Plugin Manager uses
   * createInstance(). Specifically this method is used to determine the
   * parameters to pass to the constructor.
   *
   * @param Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   *
   * @return static
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('tripal_chado.chado_buddy'),
      $container->get('tripal_chado.database'),
      $container->get('messenger'),
      $container->get('tripal.logger'),
      $container->get('tripal.fileretriever'),
      $container->get('tripal.backend_publish'),
      $container->get('file.repository'),
      $container->get('file_system'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ChadoBuddyPluginManager $buddy_manager,
    ChadoConnection $connection,
    Messenger $messenger,
    TripalLogger $logger,
    TripalFileRetriever $fileretriever,
    TripalBackendPublishManager $publish_manager,
    FileRepositoryInterface $file_repository,
    FileSystemInterface $file_system,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $connection,
      $messenger,
      $logger,
      $fileretriever,
      $publish_manager,
    );
    $this->buddy_manager = $buddy_manager;
    $this->dbxref_buddy = $this->buddy_manager->createInstance('chado_dbxref_buddy', []);
    $this->cvterm_buddy = $this->buddy_manager->createInstance('chado_cvterm_buddy', []);
    $this->file_repository = $file_repository;
    $this->file_system = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public function form($form, &$form_state) {

    $form = [];

    // The parent adds a chado schema select element.
    $form = parent::form($form, $form_state);

    // Get the sequence ontology cv_id.
    $cv_records = $this->cvterm_buddy->getCv(['cv.name' => 'sequence'], []);
    if (count($cv_records) != 1) {
      throw new \Exception('Cannot find the "sequence" ontology');
    }
    $sequence_cv_id = $cv_records[0]->getValue('cv.cv_id');

    $form_state_values = $form_state->getValues();
    $form_state_input = $form_state->getUserInput();

    // Default values can come in the following ways:
    // If we are reconstructing the form from a failed validation or ajax
    // callback then use $form_state_values.
    // If we are rebuilding the form from after submission (from an ajax
    // call) then the values are in the $form_state_input array.
    $blastdb_id = $form_state_values['blastdb_id'] ?? $form_state_input['blastdb_id'] ?? NULL;
    $blastfile_ext = $form_state_values['blastfile_ext'] ?? $form_state_input['blastfile_ext'] ?? '';
    $query_re = $form_state_values['query_re'] ?? $form_state_input['query_re'] ?? '';
    $query_type = $form_state_values['query_type'] ?? $form_state_input['query_type'] ?? '';
    $query_uniquename = $form_state_values['query_uniquename'] ?? $form_state_input['query_uniquename'] ?? 0;
    $is_concat = $form_state_values['is_concat'] ?? $form_state_input['is_concat'] ?? 0;

    $form['blastdb_id'] = $this->getDbSelectElement($blastdb_id);

    $form['blastfile_ext'] = [
      '#title' => $this->t('Blast XML file extension'),
      '#type' => 'textfield',
      '#description' => $this->t('If a directory is provided for the blast file setting above,
        then a file extension can be provided here. Files with this extension in the directory will be parsed.
        If no extension is provided, then files with a .xml extension will be parsed within the directory.
        Please provide the extension without the preceeding period (e.g. "out" rather than ".out"'),
      '#default_value' => $blastfile_ext,
    ];

    $blast2go = Link::fromTextAndUrl($this->t('Blast2GO'), Url::fromUri('https://www.blast2go.com/'))->toString();
    $form['is_concat'] = [
      '#title' => $this->t('Is the XML file concatenated?'),
      '#type' => 'checkbox',
      '#description' => $this->t('Is the XML file a set of concatenated XML results?
      Such is the case, for instance, if @blast2go was used to generate the blast results.
      If NCBI BLAST was used with output in XML then this option should not be checked.',
      ['@blast2go' => $blast2go]),
      '#default_value' => $is_concat,
    ];

    $form['no_parsed'] = [
      '#title' => $this->t('Number of hits to be parsed'),
      '#type' => 'textfield',
      '#description' => $this->t("The number of hits to be parsed. Tripal will parse only top 10 hits if you input '10' in this field. Enter the text 'all' to parse all hits. Default is to parse only the top 25 hits per match."),
      '#default_value' => '25',
    ];

    $form['query_re'] = [
      '#title' => $this->t('Query Name RE'),
      '#type' => 'textfield',
      '#description' => $this->t('Enter the regular expression that will extract the
      feature name from the query line in the blast results.
      This should be the same as the definition line in the query FASTA file.
      For example, to retrieve the first word use %regex
      This option is is only required when the query does not identically match a feature
      in the database.',
      ['%regex' => '^(\S+)']),
      '#default_value' => $query_re,
    ];

    $form['query_type'] = [
      '#title' => $this->t('Query Type'),
      '#type' => 'textfield',
      '#description' => $this->t('Please enter the Sequence Ontology term that describes
      the query sequences used for blasting.
      This is only necessary if two or more sequences have the same name.'),
      '#default_value' => $query_type,
      '#autocomplete_route_name' => 'tripal_chado.cvterm_autocomplete',
      '#autocomplete_route_parameters' => ['count' => 5, 'cv_id' => $sequence_cv_id],
    ];

    $form['query_uniquename'] = [
      '#title' => $this->t('Use Unique Name'),
      '#type' => 'checkbox',
      '#description' => $this->t('Select this checkbox if the query name in the blast file
      matches the uniquename of the feature. By default, the blast results will be
      mapped to the "name" of the feature.'),
      '#default_value' => $query_uniquename,
    ];

    return $form;
  }

  /**
   * Generates the database select form element.
   *
   * @param int|null $blastdb_id
   *   The default db_id value for the select element.
   *
   * @return array
   *   The element render array.
   */
  protected function getDbSelectElement(?int $blastdb_id): array {
    // Get the case-insensitive sorted list of databases.
    $query = $this->connection->select('1:db', 'D');
    $query->fields('D', ['db_id', 'name']);
    $query->addExpression('LOWER(name)', 'lower_name');
    $query->orderBy('lower_name');
    $dbs = ['' => '- Select -'];
    $results = $query->execute();
    while ($db_obj = $results->fetchObject()) {
      $dbs[$db_obj->db_id] = $db_obj->name;
    }

    $add_db_page = Link::fromTextAndUrl($this->t('add the database'), Url::fromUri('base://admin/tripal/terms/chado_db'))->toString();
    // @todo /admin/tripal/extension/tripal_blast_analysis
    $settings_page = Link::fromTextAndUrl($this->t('settings page'), Url::fromUri('base://@todo'))->toString();
    $element = [
      '#title' => $this->t('Database'),
      '#type' => 'select',
      '#description' => $this->t('The database used for the blast analysis.
        If the database does not appear in this list, please @add_database.
        Each database may have a different format for each match.
        This blast module will attempt to extract the match name,
        match accession, and organism from each match.
        To ensure the parser is able to properly extract this information,
        please set the proper regular expression values on the @settings_page.
        Databases from NCBI have a built-in parser. On the Blast Settings page,
        simply click the box "Use Genebank style parser"',
        [
          '@add_database' => $add_db_page,
          '@settings_page' => $settings_page,
        ]),
      '#options' => $dbs,
      '#default_value' => $blastdb_id,
    ];
    return $element;

  }

  /**
   * {@inheritdoc}
   */
  public function formValidate($form, &$form_state) {
    $values = $form_state->getValues();
    if ($values['blastfile_ext'] && !preg_match('/^[A-Za-z]+$/', $values['blastfile_ext'])) {
      $form_state->setErrorByName('blastfile_ext', 'The extension should consist of only letters');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function run() {
    $arguments = $this->arguments['run_args'];
    $analysis_id = $arguments['analysis_id'];
    $blastdb_id = $arguments['blastdb_id'];
    $blastfile = trim($this->arguments['files'][0]['file_path']);
    $blastfile_ext = trim($arguments['blastfile_ext']);
    $is_concat = $arguments['is_concat'];
    $no_parsed = $arguments['no_parsed'];
    $query_re = trim($arguments['query_re']);
    $query_type = trim($arguments['query_type']);
    $query_uniquename = $arguments['query_uniquename'];
    $search_keywords = 1;
    $this->parseXMLFile(
      $analysis_id,
      $blastdb_id,
      $blastfile,
      $no_parsed,
      $blastfile_ext,
      $query_re,
      $query_type,
      $query_uniquename,
      $is_concat,
      $search_keywords,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function formSubmit($form, &$form_state) {
    $values = $form_state->getValues();
  }

  /**
   * This function is a wrapper for the parseXml() function.
   *
   * If a directory of blast results is provided then this function will
   * iterate through all files and call the parseXML file individually for
   * each. Otherwise, if a single XML file is provided it will call the
   * function for just that file.
   *
   * @param int $analysis_id
   *   The analysis ID that the blast results belong to.
   * @param $blastdb_id
   *   The database blasted against.
   * @param string $blastfile
   *   The directory path containing a collection of blast XML files or a the
   *   path to a single XML file. If a directory is provided the function will
   *   iterate through all of the files with the extension provided by the
   *   $blast_ext argument and extract results.
   * @param $no_parsed
   *   The number of hits to keep.
   * @param string $blastfile_ext
   *   If the $blastfile argument is a directory path rather than a file, then
   *   this function will look for files with the extension provided.
   * @param string $query_re
   *   The regular expression that can uniquely identify the query name.
   *   This parameter is required if the feature name is not the
   *   first word in the blast query name.
   * @param string $query_type
   *   The feature type (e.g. 'gene', 'mRNA', 'contig') of the query. It must
   *   be a valid Sequence Ontology term.
   * @param string $query_uniquename.
   *   The regular expression that can uniquely identify the query unique name.
   *   This parameters is required if the feature name is not the
   *   first word in the blast query name.
   * @param $is_concat
   *   If the blast result file is simply a list of concatenated blast results
   *   then this value should be set to 1.
   * @param string $search_keywords
   *   The list of fields to extract from the blast results and store as
   *   a separate entry in the analysisfeatureprop table. The list should be
   *   a single string with elements separated with a bar '|'. The valid values
   *   include: 'blast_match_name', 'blast_match_description', 'blast_match_organism'
   *   'blast_match_accession', 'blast_database', 'blast_evalue', 'blast_score'.
   *
   * @return int
   *   Returns 1 for success, 0 for failure.
   */
  protected function parseXmlFile(
    int $analysis_id,
    $blastdb_id,
    string $blastfile,
    $no_parsed,
    string $blastfile_ext,
    string $query_re,
    string $query_type,
    string $query_uniquename,
    $is_concat,
    string $search_keywords,
  ): int {
    try {
      // If user supplied a single file (e.g. blast.xml).
      if (is_file($blastfile)) {
        $this->parseXml($analysis_id, $blastdb_id, $blastfile,
          $no_parsed, $blastfile_ext, $query_re, $query_type, $query_uniquename,
          $is_concat, $search_keywords);
      }
      // Otherwise, $blastfile is a directory. Iterate through all xml files in it.
      else {
        if (!$blastfile_ext) {
          $blastfile_ext = 'xml';
        }

        // Parsing all files in the directory.
        $dir_handle = @opendir($blastfile) or die("Unable to open $blastfile");
        $files_to_parse = [];
        while ($file = readdir($dir_handle)) {
          if (preg_match("/^.*\.$blastfile_ext/i", $file)) {
            $files_to_parse[] = $file;
          }
        }

        $no_file = 0;
        $total_files = count($files_to_parse);
        $this->setTotalItems($total_files);
        foreach ($files_to_parse as $file) {
          $no_file++;
          $this->logger->notice(
            $this->t('T_blastXML_load: File @no_file of @total_files: @file',
            ['@no_file' => $no_file, '@total_files' => $total_files, '@file' => $file])
          );
          $this->parseXML($analysis_id, $blastdb_id, "$blastfile/$file",
            $no_parsed, $blastfile_ext, $query_re, $query_type, $query_uniquename,
            $is_concat, $search_keywords, FALSE);
          $this->setItemsHandled($no_file);
        }
      }
    }
    catch (Exception $e) {
      $this->logger->error("T_blastXML_load: Error parsing XML file $blastfile");
      return 0;
    }
    $this->logger->notice("T_blastXML_load: Completed parsing XML file $blastfile");
    return 1;
  }

  /**
   * Parse Blast XML Output file and store in the analysisfeatureprop table.
   *
   * Parse NCBI BLAST XML results into chunks of XML for each feature and store
   * them in the analysisfeatureprop table.
   *
   * @param int $analysis_id
   *   The analysis ID that the blast results belong to.
   * @param int|null $blastdb_id
   *   The database blasted against.
   * @param $blastfile
   *   The directory path containing a collection of blast XML files or a the
   *   path to a single XML file.  If a directory is provided the function will
   *   iterate through all of the files with the extension provided by the
   *   $blast_ext argument and extract results.
   * @param $no_parsed
   *   The number of hits to keep.
   * @param $blast_ext
   *   If the $blastfile argument is a directory path rather than a file then
   *   this function willlook for files with the extension provided.
   * @param $query_re
   *   The regular expression that can uniquely identify the query name.
   *   This parameter is required if the feature name is not the
   *   first word in the blast query name.
   * @param $query_type
   *   The feature type (e.g. 'gene', 'mRNA', 'contig') of the query. It must
   *   be a valid Sequence Ontology term.
   * @param $query_uniquename
   *   The regular expression that can uniquely identify the query unique name.
   *   This parameters is required if the feature name is not the
   *   first word in the blast query name.
   * @param $is_concat
   *   If the blast result file is simply a list of concatenated blast results
   *   then this value should be set to 1.
   * @param $search_keywords
   *   The list of fields to extract from the blast results and store as
   *   a separate entry in the analysisfeatureprop table.  Th list should be
   *   a single string with elements separated with a bar '|'.  The valid values
   *   include:  'blast_match_name', 'blast_match_description', 'blast_match_organism'
   *   'blast_match_accession', 'blast_database', 'blast_evalue', 'blast_score'
   *
   * @return bool
   *   TRUE for success or FALSE for error.
   */
  protected function parseXml(
    int $analysis_id,
    ?int $blastdb_id,
    $blastfile,
    $no_parsed,
    $blastfile_ext,
    $query_re,
    $query_type,
    $query_uniquename,
    $is_concat,
    $search_keywords,
    $progress = TRUE,
  ): bool {

    // Get the cvterm_id for 'analysis_blast_output_iteration_hits' which
    // is required for inserting into the analysisfeatureprop table.
    $cvterm_records = $this->cvterm_buddy->getCvterm(['cv.name' => 'local', 'cvterm.name' => 'analysis_blast_output_iteration_hits'], []);
    if (count($cvterm_records) != 1) {
      $this->logger->error("T_blastXML_load: Cannot find the \"local:analysis_blast_output_iteration_hits\" cv term.");
      return FALSE;
    }
    $type_id = $cvterm_records[0]->getValue('cvterm.cvterm_id');

    // Get the query cvterm_id from the form query_type field.
    $query_type_id = NULL;
    if ($query_type) {
      $cv_autocomplete = new ChadoCVTermAutocompleteController();
      $query_type_id = $cv_autocomplete->getCVtermId($query_type, 'sequence');
      if (!$query_type_id) {
        $this->logger->error("T_blastXML_load: Invalid cv term supplied for query type: \"$query_type\".");
        return FALSE;
      }
    }

    // Retrieve the blast database object.
    $query = $this->connection->select('1:db', 'D');
    $query->fields('D');
    $query->condition('D.db_id', $blastdb_id, '=');
    $blastdb_object = $query->execute()->fetchObject();

    // Retrieve the analysis object.
    $query = $this->connection->select('1:analysis', 'A');
    $query->fields('A');
    $query->condition('A.analysis_id', $analysis_id, '=');
    $analysis_object = $query->execute()->fetchObject();

    // Validate readability of the XML file as the job runner
    // may not have the same access as the web server.
    if (!is_readable($blastfile)) {
      $this->logger->error("T_blastXML_load: Could not open the XML file \"$blastfile\". Check that the file exists and that permissions are correct.");
      return FALSE;
    }

    // If the file is a set of concatenated files then this will split
    // them so that we can process each one separately.
    $this->splitXml($blastfile);
    foreach ($this->temp_files as $file) {
      $this->logger->notice('T_blastXML_load: Parsing file "' . $file . '" from "' . $blastfile . '"');

      $blastoutput = new \XMLReader();

      // Parse the file initially to get the number of blast
      // iterations elements so that we can display progress.
      $iteration_count = 0;
      if ($progress) {
        $blastoutput->open($file);
        while ($blastoutput->read()) {
          if ($blastoutput->nodeType == \XMLReader::ELEMENT) {
            if (strcmp($blastoutput->name, 'Iteration') == 0) {
              $iteration_count++;
            }
          }
        }
        $blastoutput->close();
        $this->setTotalItems($iteration_count);
      }

      // Reopen the file and iterate through the nodes until we
      // get to the BlastOutput_iterations section.
      $blastoutput->open($file);
      $num_read = 0;
      while ($blastoutput->read()) {
        if ($blastoutput->nodeType == \XMLReader::ELEMENT) {
          if (strcmp($blastoutput->name, 'BlastOutput_iterations') == 0) {
            // Iterate through each Iteration (without descending into the
            // subtree) to count the number of iterations in the file.
            while ($blastoutput->read()) {
              if ($blastoutput->nodeType == \XMLReader::ELEMENT) {
                // This should be the <Iteration> tag.
                $success = $this->handleIteration($blastoutput, $analysis_object, $blastdb_object, $blastfile,
                  $no_parsed, $blastfile_ext, $query_re, $query_type_id, $query_uniquename,
                  $is_concat, $search_keywords, $type_id, $search_keywords);
                if (!$success) {
                  return FALSE;
                }
                $num_read++;
                if ($progress) {
                  $this->setItemsHandled($num_read);
                }
              }
            }
          }
        }
      }
    }
    return TRUE;
  }

  /**
   * Splits a multi-result xml file into individual temporary files.
   *
   * The number of temporary files created will match the number of
   * <BlastOutput> sections. There may be one or several.
   *
   * @param string $blastfile
   *   A blast XML file name containing one or more <BlastOutput> elements.
   *
   * @return bool
   *   TRUE for success, FALSE for error.
   *   The file system real paths to the temporary files are stored
   *   in the class variable $this->temp_files.
   */
  protected function splitXml(string $blastfile): bool {
    $this->cleanTempFiles();
    $header = '';
    $saving_header = TRUE;
    $out_fh = NULL;

    // @todo use $this->tripalFopen() to support .gz files
    // once PR #2610 is merged.
    $in_fh = fopen($blastfile, "r");
    while (!feof($in_fh)) {
      $line = fgets($in_fh);
      $line = trim($line);
      if ($line) {
        if (preg_match("/<BlastOutput>/", $line)) {
          $saving_header = FALSE;
          $temp_name = 'temporary://tripal_analysis_blast_temp_' . getmypid() . '_' . count($this->temp_files) . '.xml';
          $real_path = $this->file_system->realpath($temp_name);
          $this->temp_files[] = $real_path;
          $out_fh = fopen($real_path, 'w');
          fwrite($out_fh, $header);
        }
        if ($saving_header) {
          // In the event that this is not a valid blast XML file, we
          // want to avoid loading the entire file into memory.
          if (strlen($header) > 10000) {
            $this->logger->error('T_blastXML_load: The file "' . $blastfile . '" does not contain valid blast XML');
            fclose($in_fh);
            fclose($out_fh);
            $this->cleanTempFiles();
            return FALSE;
          }
          $header .= $line . "\n";
        }
        else {
          // This would trigger if there is anything other than white
          // space outside the <BlastOutput> element.
          if (!$out_fh) {
            $this->logger->error('T_blastXML_load: The file "' . $blastfile . '" does not contain valid blast XML');
            fclose($in_fh);
            $this->cleanTempFiles();
            return FALSE;
          }
          fwrite($out_fh, $line . "\n");
        }
      }
      // If the line ended a set of blast output XML then
      // close the current temporary file.
      if (preg_match("/<\/BlastOutput>/", $line)) {
        fclose($out_fh);
        $out_fh = NULL;
      }
    }
    fclose($in_fh);
    if (!$this->temp_files || $out_fh) {
      $this->logger->error('T_blastXML_load: The file "' . $blastfile . '" does not contain valid blast XML');
      if ($out_fh) {
        fclose($out_fh);
      }
      $this->cleanTempFiles();
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Removes any temporary files created during import.
   *
   * @return void
   *   No return value.
   */
  protected function cleanTempFiles(): void {
    foreach ($this->temp_files as $file) {
      if (file_exists($file)) {
        unlink($file);
      }
    }
    $this->temp_files = [];
  }

  /**
   * Process one <Iteration> element.
   *
   *
   * @return bool
   *   TRUE for success, FALSE if error.
   */
  protected function handleIteration(
    $blastoutput,
    object $analysis_object,
    object $blastdb_object,
    $blastfile,
    $no_parsed,
    $blastfile_ext,
    $query_re,
    $query_type_id,
    $query_uniquename,
    $is_concat,
    $search_keywords,
    $type_id,
  ): bool {

    $feature_id = 0;
    $analysisfeature_id = 0;
    $iteration_tags_xml = '';
    $num_hits = 1;

    // Iterate though the child nodes of the <Iteration> tag.
    while ($blastoutput->read()) {
      // If we've hit the closing tag (</Iteration>) then return.
      if ($blastoutput->nodeType == \XMLReader::END_ELEMENT) {
        if (strcmp($blastoutput->name, 'Iteration') == 0) {
          return TRUE;
        }
      }
      // Act based on the type of element.
      if ($blastoutput->nodeType == \XMLReader::ELEMENT) {
        $tag_name = $blastoutput->name;
        $blastoutput->read();
        $value = $blastoutput->value;

        switch ($tag_name) {

          // If the node is <Iteration_query-def>.
          case 'Iteration_query-def':

            $iteration_tags_xml .= "  <$tag_name>$value</$tag_name>\n";

            // If the user provided a query RE to extract the feature name
            // then use that now.
            if ($query_re and preg_match("/$query_re/", $value, $matches)) {
              $feature = $matches[1];
            }
            // If not a query RE or match then use the default word before
            // a space as the feature name.
            elseif (preg_match('/^(.*?)\s.*$/', $value, $matches)) {
              $feature = $matches[1];
            }
            // If no match up to the first space then just use the entire
            // string.
            else {
              $feature = $value;
            }

            if (!$feature and $query_re) {
              $this->logger->error("T_blastXML_load: Cannot find feature in '$value' using the " .
                "regular expression: $query_re");
              return FALSE;
            }

            // Now find the feature in chado.
            $query = $this->connection->select('1:feature', 'F');
            $query->addField('F', 'feature_id', 'feature_id');
            if ($query_uniquename) {
              $query->condition('F.uniquename', $feature, '=');
            }
            else {
              $query->condition('F.name', $feature, '=');
            }
            if ($query_type_id) {
              $query->condition('F.type_id', $query_type_id, '=');
            }
            $feature_arr = $query->execute()->fetchAll();
            $suffix = $query_type_id ? ' using type_id ' . $query_type_id : '';
            if (!$feature_arr) {
              $this->logger->error("T_blastXML_load: Database query failed when searching for feature '$value'$suffix.");
              return FALSE;
            }
            if (count($feature_arr) > 1) {
              $this->logger->error("T_blastXML_load: Ambiguous: '$feature' matches more than one " .
                "feature$suffix and is being skipped");
              continue 2;
            }
            if (count($feature_arr) == 0) {
              $this->logger->error("T_blastXML_load: Failed: '$feature' cannot find a matching " . "
                     feature in the database$suffix.");
              continue 2;
            }
            $feature_id = $feature_arr[0]->feature_id;
            break;

          // If the node is <Iteration_hits>.
          case 'Iteration_hits':
            if (!$feature_id) {
              $this->logger->error("T_blastXML_load: Cannot add blast results as feature_id is missing.");
              continue 2;
            }
            // We are going to store the XML in the analysisfeatureprop table so we need to rebuild it.
            $xml_content = "<Iteration>\n" . $iteration_tags_xml . "    <$tag_name>\n";

            // Iterate through each of the <Hit> tags, extract the XML and add to our $xml_content.
            while ($blastoutput->next()) {

              // If we've reached the node </Iteration> then break out
              // of the while loop.
              if ($blastoutput->nodeType == \XMLReader::END_ELEMENT) {
                if (strcmp($blastoutput->name, 'Iteration_hits') == 0) {
                  break;
                }
              }
              // For each hit, just get the contents as a block of XML. We'll
              // store it as XML in the analysisfeatureprop table.
              if ($blastoutput->nodeType == \XMLReader::ELEMENT) {
                if (strcmp($blastoutput->name, 'Hit') == 0) {
                  // Parse only the hits requested to parse.
                  if ($no_parsed == 'all' or $num_hits <= $no_parsed) {
                    // Don't need ending \n as it's included in readInnerXML().
                    $xml_content .= "<Hit>";
                    $xml_content .= $blastoutput->readInnerXML();
                    // Don't need spacing before tag as it's included
                    // in readInnerXML().
                    $xml_content .= "</Hit>\n";
                  }
                }
                $num_hits++;
              }
            }
            $xml_content .= "\n  </$tag_name>\n</Iteration>";

            // Make sure this iteration doesn't exist in analysisfeatureprop. If it does, update but not insert.
            $sql = "SELECT AFP.analysisfeature_id, AFP.analysisfeatureprop_id
            FROM {1:analysisfeatureprop} AFP
              INNER JOIN {1:analysisfeature} AF ON AF.analysisfeature_id = AFP.analysisfeature_id
            WHERE feature_id = :feature_id AND analysis_id = :analysis_id AND type_id = :type_id";
            $results = $this->connection->query($sql, [
              ':feature_id' => $feature_id,
              ':analysis_id' => $analysis_object->analysis_id,
              ':type_id' => $type_id,
            ]);
            $analysisfeatureprop = $results->fetchObject();

            // If the analysis feature already exists then get that record.
            $query = $this->connection->select('1:analysisfeature', 'AF');
            $query->addField('AF', 'analysisfeature_id', 'analysisfeature_id');
            $query->condition('AF.feature_id', $feature_id, '=');
            $query->condition('AF.analysis_id', $analysis_object->analysis_id, '=');
            $analysisfeature_id = $query->execute()->fetchField();
            if (!$analysisfeature_id) {
              $query = $this->connection->insert('1:analysisfeature');
              $query->fields(['feature_id' => $feature_id, 'analysis_id' => $analysis_object->analysis_id]);
              $analysisfeature_id = $query->execute();
              if (!$analysisfeature_id) {
                $this->logger->error("T_blastXML_load: Cannot add analysis feature record for $feature.");
                continue 2;
              }
            }

            // If the analysisfeatureprop record exists then update it.
            if ($analysisfeatureprop) {
              $sql = "UPDATE {1:analysisfeatureprop}
              SET value = :xml_content
              WHERE analysisfeatureprop_id = :analysisfeatureprop_id";
              $success = $this->connection->query($sql, [':xml_content' => $xml_content, 'analysisfeatureprop_id' => $analysisfeatureprop->analysisfeatureprop_id]);
              if (!$success) {
                $this->logger->error("T_blastXML_load: Cannot update analysis feature property record for $feature.");
                continue 2;
              }
            }
            // If the analyisfeatureprop record doesn't exist then add it.
            else {

              // Add the analysisfeatureprop record.
              $values = [
                'analysisfeature_id' => $analysisfeature_id,
                'type_id' => $type_id,
                'value' => $xml_content,
                'rank' => 0,
              ];
              $query = $this->connection->insert('1:analysisfeatureprop');
              $query->fields($values);
              $analysisfeatureprop_id = $query->execute();
              if (!$analysisfeatureprop_id) {
                $this->logger->error("T_blastXML_load: Cannot add analysis feature property record.");
                continue 2;
              }
            }
            // If the user wants to store the keywords then do so.
            if ($search_keywords) {

              // Remove any existing entries. we'll replace them.
              $match = ['analysisfeature_id' => $analysisfeature_id];
              $query = $this->connection->delete('1:blast_hit_data');
              $query->condition('analysisfeature_id', $analysisfeature_id, '=');
              $query->execute();

              // Get the blast object.
              $blast_obj = $this->getResultObject($xml_content, $blastdb_object, $feature_id, $analysis_object);

              // Iterate through the hits and add the records to the
              // blast_hit_data table.
              $failed = 0;
              for ($i = 0; $i < $blast_obj->number_hits; $i++) {
                // Add the organism to the blast_organisms table, but
                // first check to make sure it doesn't already exist.
                // Note that this is not connected to chado.organism_id.
                $blast_org_name = $blast_obj->hits_array[$i]['hit_organism'];
                if ($blast_org_name) {
                  $query = $this->connection->select('1:blast_organisms', 'O');
                  $query->addField('O', 'blast_org_id', 'blast_org_id');
                  $query->condition('O.blast_org_name', $blast_org_name, '=');
                  $blast_org_id = $query->execute()->fetchField();
                  // If this organism doesn't exist then add it.
                  if (!$blast_org_id) {
                    $query = $this->connection->insert('1:blast_organisms');
                    $query->fields(['blast_org_name' => $blast_org_name]);
                    $blast_org_id = $query->execute();
                    if (!$blast_org_id) {
                      $this->logger->error("T_blastXML_load: Failed to add blast organism: '$blast_org_name'");
                      return FALSE;
                    }
                  }
                }

                $values = [
                  'analysisfeature_id' => $analysisfeature_id,
                  'analysis_id' => $analysis_object->analysis_id,
                  'feature_id' => $feature_id,
                  'db_id' => $blastdb_object->db_id,
                  'hit_num' => $i + 1,
                  'hit_name' => $blast_obj->hits_array[$i]['hit_name'],
                  'hit_url' => $blast_obj->hits_array[$i]['hit_url'],
                  'hit_description' => $blast_obj->hits_array[$i]['description'],
                  'hit_organism' => $blast_org_name,
                  'blast_org_id' => $blast_org_id,
                  'hit_accession' => $blast_obj->hits_array[$i]['accession'],
                  'hit_best_eval' => $blast_obj->hits_array[$i]['best_evalue'],
                  'hit_best_score' => $blast_obj->hits_array[$i]['best_score'],
                  'hit_pid' => $blast_obj->hits_array[$i]['percent_identity'],
                ];
                $query = $this->connection->insert('1:blast_hit_data');
                $query->fields($values);
                // Because this table has no primary key, we have no return
                // value to check. Catch any exception instead.
                try {
                  $query->execute();
                }
                catch (\Throwable $e) {
                  $failed = 1;
                }
              }
              if ($failed) {
                $this->logger->error("T_blastXML_load: Failed to add keywords for searching: '$feature'");
                return FALSE;
              }
            } // end if ($search_keywords) ...
            break;

          default:
            $iteration_tags_xml .= "  <$tag_name>$value</$tag_name>\n";
            break;
        }
      }
    }
  }

  /**
   * Parses the BLAST XML for a single iteration (feature matches).
   *
   * An object is returned containing the results.
   *
   * @param string $xml_string
   *   The XML results for a single iteration. The XML should begin with the
   *   <Iteration> tag.
   * @param object $db
   *   An instantiated object containing the database record of the database
   *   the features were blasted against.
   * @param string $feature_id
   *   The feature ID to which this blast XML belongs.
   * @param object $analysis
   *   An instantiated object containing the analysis record of the blast
   *   analysis.
   *
   * @return object|null
   *   An object with the following fields:
   *
   *      Incoming arguments:
   *        $blast_obj->analysis     (the analysis object)
   *        $blast_obj->db           (the database object)
   *        $blast_obj->max          (the maximum number of hits)
   *        $blast_obj->feature_id   (the feature ID of this match)
   *
   *      Meta Information about the results
   *        $blast_obj->xml_tag      (the feature name)
   *        $blast_obj->number_hits  (the number of hits in the array)
   *        $blast_obj->title        (the database name--or analysis if no dbname)
   *
   *      The hits array. The variable $i is indicate an index variable that needs
   *      to be used for accessing the hits.
   *        $blast_obj->hits_array
   *        $blast_obj->hits_array[$i]['arrowr_url']
   *        $blast_obj->hits_array[$i]['accession']
   *        $blast_obj->hits_array[$i]['hit_name']
   *        $blast_obj->hits_array[$i]['hit_url']
   *        $blast_obj->hits_array[$i]['best_evalue']
   *        $blast_obj->hits_array[$i]['best_score']
   *        $blast_obj->hits_array[$i]['percent_identity']
   *        $blast_obj->hits_array[$i]['description']
   *
   *      The HSPs for a given hit. The variable $j indicates an index variable that
   *      needs to be used for accessing the HSPs.
   *        $blast_obj->hits_array[$i]['hsp'][$j]['query_frame']
   *        $blast_obj->hits_array[$i]['hsp'][$j]['hsp_num']
   *        $blast_obj->hits_array[$i]['hsp'][$j]['bit_score']
   *        $blast_obj->hits_array[$i]['hsp'][$j]['score']
   *        $blast_obj->hits_array[$i]['hsp'][$j]['evalue']
   *        $blast_obj->hits_array[$i]['hsp'][$j]['query_frame']
   *        $blast_obj->hits_array[$i]['hsp'][$j]['qseq']
   *        $blast_obj->hits_array[$i]['hsp'][$j]['midline']
   *        $blast_obj->hits_array[$i]['hsp'][$j]['hseq']
   *        $blast_obj->hits_array[$i]['hsp'][$j]['hit_from']
   *        $blast_obj->hits_array[$i]['hsp'][$j]['hit_to']
   *        $blast_obj->hits_array[$i]['hsp'][$j]['identity']
   *        $blast_obj->hits_array[$i]['hsp'][$j]['align_len']
   *        $blast_obj->hits_array[$i]['hsp'][$j]['positive']
   *        $blast_obj->hits_array[$i]['hsp'][$j]['query_from']
   *        $blast_obj->hits_array[$i]['hsp'][$j]['query_to']
   */
  protected function getResultObject(
    string $xml_string,
    object $db,
    string $feature_id,
    object $analysis,
  ): ?object {
    $blast_object = new \stdClass();

    $blast_object->xml = $xml_string;

    // Get the parser using db_id.
    $sql = "SELECT * FROM {0:tripal_analysis_blast} WHERE db_id = :db_id";
    $parser = $this->connection->query($sql, [':db_id' => $db->db_id])->fetchObject();

    $db_name = '';
    $is_genbank = '';
    $regex_hit_id = '';
    $regex_hit_def = '';
    $regex_hit_organism = '';
    $regex_hit_accession = '';
    $db_organism = '';

    // Set defaults.
    if ($parser) {
      $db_name = $parser->displayname;
      $is_genbank = $parser->genbank_style;
      $regex_hit_id = $parser->regex_hit_id;
      $regex_hit_def = $parser->regex_hit_def;
      $regex_hit_organism = $parser->regex_hit_organism;
      $regex_hit_accession = $parser->regex_hit_accession;
      $db_organism = $parser->hit_organism;
    }

    // Set default if regular expressions have not been specified.
    if (!$regex_hit_id) {
      $regex_hit_id = '/^(.*?)\s.*$/';
    }
    else {
      $regex_hit_id = '/' . $regex_hit_id . '/';
    }
    if (!$regex_hit_def) {
      $regex_hit_def = '/^.*?\s(.*)$/';
    }
    else {
      $regex_hit_def = '/' . $regex_hit_def . '/';
    }
    if (!$regex_hit_accession) {
      $regex_hit_accession = '/^(.*?)\s.*$/';
    }
    else {
      $regex_hit_accession = '/' . $regex_hit_accession . '/';
    }
    if ($regex_hit_organism) {
      $regex_hit_organism = '/' . $regex_hit_organism . '/';
    }

    // Get analysis information.
    $blast_object->analysis = $analysis;
    $db->displayname = $db_name;
    $blast_object->db = $db;
    if (!$db_name) {
      $blast_object->title = $analysis->name;
    }
    else {
      $blast_object->title = $db_name;
    }

    // Find entity id for the analysis.
    # @todo
    #if (module_exists('tripal_analysis')) {
    #  $sql = "SELECT nid FROM {1:chado_analysis} WHERE analysis_id = :analysis_id";
    #  $args = [':analysis_id' => $analysis->analysis_id];
    #  $ana_nid = $this->connection->query($sql, $args)->fetchField();
    #  $analysis->nid = $ana_nid;
    #}

    // Load the file.  This XML file should be an extract
    // of the original XML file with only a single iteration.
    // An iteration is essentially all the hits for a single
    // query sequence.
    $xml = new \XMLReader();
    $xml->xml($xml_string);
    $iteration = '';

    // Iterate though the child nodes of the <Iteration> tag.
    while ($xml->read()) {
      // If we've hit the closing tag (</Iteration>) then return.
      if ($xml->nodeType == \XMLReader::END_ELEMENT) {
        if (strcmp($xml->name, 'Iteration') == 0) {
          // we're done.
          return NULL;
        }
      }

      // If this is the start of an iteration element.
      if ($xml->nodeType == \XMLReader::ELEMENT) {
        $iteration_element_name = $xml->name;
        $xml->read();
        $iteration_element_value = $xml->value;

        switch ($iteration_element_name) {
          case 'Iteration_query-def':
            $blast_object->xml_tag = $iteration_element_value;
            break;

          case 'Iteration_hits':
            $blast_object->xml_tag = $iteration_element_value;

            // Add the links for updating blast info using Ajax.
            $blast_object->feature_id = $feature_id;

            // Initalize hit variables.
            $hits_array = [];
            $hit_count = 0;
            $number_hits = 0;
            $accession = '';
            $hit_name = '';
            $description = '';
            $hit_organism = 'Unknown';

            // Initalize hsp variables.
            $hsp_array = [];
            $counter = 0;
            $best_evalue = 0;
            $best_score = 0;
            $best_identity = 0;
            $best_len = 0;

            // Iterate through hits.
            while ($xml->read()) {

              // If we're at the begining of the hit then reset variables.
              if ($xml->nodeType == \XMLReader::ELEMENT) {
                if (strcmp($xml->name, 'Hit') == 0) {
                  $hsp_array = [];
                  $counter = 0;
                  $best_evalue = 0;
                  $best_score = 0;
                  $best_identity = 0;
                  $best_len = 0;
                  $accession = '';
                  $hit_name = '';
                  $description = '';
                  $hit_organism = 'Unknown';
                }
              }

              // If we're at the beginning of any other element then collect data.
              if ($xml->nodeType == \XMLReader::ELEMENT) {
                $hit_element_name = $xml->name;
                $xml->read();
                $hit_element_value = $xml->value;

                switch ($hit_element_name) {
                  case 'Hit_id':
                    // If parsing "name, acc, desc" from three tags (1/3)
                    if ($is_genbank) {
                      $hit_name = $hit_element_value;
                    }
                    break;

                  case 'Hit_def':
                    if ($is_genbank) {
                      $description = $hit_element_value;
                      // Look for the organism in the definition.
                      $hit_organism = preg_replace('/^.*\[(.*?)\].*$/', "$1", $hit_element_value, 1, $count);
                      if ($count == 0) {
                        $hit_organism = 'Unknown';
                      }
                    }
                    else {
                      if ($hit_element_value != 'No definition line') {
                        $accession = preg_replace($regex_hit_accession, "$1", $hit_element_value);
                        $hit_name = preg_replace($regex_hit_id, "$1", $hit_element_value);
                        $description = preg_replace($regex_hit_def, "$1", $hit_element_value);
                        if ($regex_hit_organism) {
                          $hit_organism = preg_replace($regex_hit_organism, "$1", $hit_element_value);
                        }
                        elseif ($db_organism) {
                          $hit_organism = $db_organism;
                        }
                      }
                    }
                    break;

                  case 'Hit_accession':
                    // If parsing "name, acc, desc" from three tags (3/3)
                    // @todo isn't this always valid?
                    // if ($is_genbank) {
                    $accession = $hit_element_value;
                    // }
                    break;

                  case 'Hit_hsps':
                    // Iterate through the HSP children.
                    while ($xml->read()) {

                      // If we're at the beginning of a new element collect data.
                      if ($xml->nodeType == \XMLReader::ELEMENT) {
                        $hsp_element_name = $xml->name;
                        $xml->read();
                        $hsp_info = $xml->value;
                        switch ($hsp_element_name) {
                          case 'Hsp_num':
                            $hsp_num = $hsp_info;
                            break;

                          case 'Hsp_bit-score':
                            $hsp_bit_score = $hsp_info;
                            break;

                          case 'Hsp_score':
                            $hsp_score = $hsp_info;
                            // Use the first score for this set of HSPs
                            // as the best score. This get's shown as
                            // info for the overall match.
                            if (!$best_score) {
                              $best_score = $hsp_score;
                            }
                            break;

                          case 'Hsp_evalue':
                            $hsp_evalue = $hsp_info;
                            // Use the first evalue for this set of HSPs
                            // as the best evalue. This get's shown as
                            // info for the overall match.
                            if (!$best_evalue) {
                              $best_evalue = $hsp_evalue;
                            }
                            break;

                          case 'Hsp_query-from':
                            $hsp_query_from = $hsp_info;
                            break;

                          case 'Hsp_query-to':
                            $hsp_query_to = $hsp_info;
                            break;

                          case 'Hsp_hit-from':
                            $hsp_hit_from = $hsp_info;
                            break;

                          case 'Hsp_hit-to':
                            $hsp_hit_to = $hsp_info;
                            break;

                          case 'Hsp_query-frame':
                            $hsp_query_frame = $hsp_info;
                            break;

                          case 'Hsp_identity':
                            $hsp_identity = $hsp_info;
                            // Use the first evalue for this set of HSPs
                            // as the best evalue. This get's shown as
                            // info for the overall match.
                            if (!$best_identity) {
                              $best_identity = $hsp_identity;
                            }
                            break;

                          case 'Hsp_positive':
                            $hsp_positive = $hsp_info;
                            break;

                          case 'Hsp_align-len':
                            $hsp_align_len = $hsp_info;
                            // Use the first evalue for this set of HSPs
                            // as the best evalue. This get's shown as
                            // info for the overall match.
                            if (!$best_len) {
                              $best_len = $hsp_align_len;
                            }
                            break;

                          case 'Hsp_qseq':
                            $hsp_qseq = $hsp_info;
                            break;

                          case 'Hsp_hseq':
                            $hsp_hseq = $hsp_info;
                            break;

                          case 'Hsp_midline':
                            $hsp_midline = $hsp_info;
                            break;
                        } // end switch ($hsp_element_name) { ...
                      } //  end if ($xml->nodeType == \XMLReader::ELEMENT) { ...

                      // If we're at the end of the HSP then set the values
                      // that we gather through previous iterations in this while loop.
                      if ($xml->nodeType == \XMLReader::END_ELEMENT) {
                        if (strcmp($xml->name, 'Hsp') == 0) {
                          $hsp_content = [];
                          $hsp_content['hsp_num'] = $hsp_num;
                          $hsp_content['bit_score'] = $hsp_bit_score;
                          $hsp_content['score'] = $hsp_score;
                          $hsp_content['evalue'] = $hsp_evalue;
                          $hsp_content['query_frame'] = $hsp_query_frame;
                          $hsp_content['qseq'] = $hsp_qseq;
                          $hsp_content['midline'] = $hsp_midline;
                          $hsp_content['hseq'] = $hsp_hseq;
                          $hsp_content['hit_from'] = $hsp_hit_from;
                          $hsp_content['hit_to'] = $hsp_hit_to;
                          $hsp_content['identity'] = $hsp_identity;
                          $hsp_content['align_len'] = $hsp_align_len;
                          $hsp_content['positive'] = $hsp_positive;
                          $hsp_content['query_from'] = $hsp_query_from;
                          $hsp_content['query_to'] = $hsp_query_to;
                          $hsp_array[$counter] = $hsp_content;
                          $counter++;
                        }

                        // If we're at the end of the <Hit_hsps> element then
                        // break out of this while loop.
                        if (strcmp($xml->name, 'Hit_hsps') == 0) {
                          break;
                        }
                      } // end if ($xml->nodeType == \XMLReader::END_ELEMENT) { ...
                    } // end while ($xml->read()) { ...
                    break;
                } // end switch ($hit_element_name) { ...
              } // end if ($xml->nodeType == \XMLReader::ELEMENT) {

              // If we're at the end of the <Hit> then add this hit to the
              // hits_array.
              if ($xml->nodeType == \XMLReader::END_ELEMENT) {
                if (strcmp($xml->name, 'Hit') == 0) {

                  $number_hits++;
                  $hits_array[$hit_count]['accession'] = $accession;
                  $hits_array[$hit_count]['hit_organism'] = $hit_organism;
                  $hits_array[$hit_count]['hit_name'] = $hit_name;

                  if ($accession && $db->urlprefix) {
                    // Replace {accession} placeholder with accession if
                    // present, otherwise append accession to url.
                    $hit_url = $db->urlprefix;
                    if (!preg_replace('/{accession}/', $accession, $hit_url)) {
                      $hit_url .= $accession;
                    }
                    $hits_array[$hit_count]['hit_url'] = $hit_url;
                  }
                  else {
                    // Test if this is another feature in the database.
                    $sql = "SELECT feature_id FROM {1:feature} WHERE uniquename = :uniquename";
                    $hit_feature_id = $this->connection->query($sql, [':uniquename' => $hit_name])->fetchField();
                    // If it is, add link to that feature.
                    if ($hit_feature_id) {
                      $hits_array[$hit_count]['hit_url'] = "ID$hit_feature_id";
                    }
                    else {
                      $hits_array[$hit_count]['hit_url'] = NULL;
                    }
                  }

                  $hits_array[$hit_count]['best_evalue'] = $best_evalue;
                  $hits_array[$hit_count]['best_score'] = $best_score;

                  if (!empty($best_len)) {
                    $percent_identity = number_format($best_identity / $best_len * 100, 2);
                    $hits_array[$hit_count]['percent_identity'] = $percent_identity;
                  }

                  $hits_array[$hit_count]['description'] = $description;

                  // If there is at least one HSP.
                  if (isset($hsp_array[0]['query_frame'])) {
                    $hits_array[$hit_count]['hsp'] = $hsp_array;
                  }
                  else {
                    $hits_array[$hit_count]['hsp'] = [];
                  }

                  $hit_count++;
                } // end if (strcmp($xml->name, 'Hit') == 0) { ...
              }  // end if ($xml->nodeType == \XMLReader::END_ELEMENT) ...
            } // end while ($xml->read()) { ...
            // End case 'Iteration_hits': ...
            break;

        } // end switch ($tag_name) { ...
      } // end if ($xml->nodeType == \XMLReader::ELEMENT) { ...
    } // end while ($xml->read()) { ...

    $blast_object->number_hits = $number_hits;
    $blast_object->hits_array = $hits_array;
    return $blast_object;
  }

  /**
   * Parse the best hit to generate the best hit homology report.
   *
   * THIS IS NEVER CALLED IN TRIPAL 3!
   *
   * @param int $analysis_id
   *   The pkey of an analysis.
   *
   * @return void
   *   No return value.
   */
  protected function parseBestHit(int $analysis_id): void {

    // Select all features for this blast analysis, and save them to the 'feature_set' array.
    $query1 = $this->connection->select('1:analysisfeature', 'AF');
    $query1->condition('AF.analysis_id', $analysis_id, '=');
    $query1->addField('AF', 'feature_id', 'feature_id');
    $result1 = $query1->execute();
    $feature_set = [];
    $counter = 0;
    while ($feature = $result1->fetchObject()) {
      $feature_set[$counter] = $feature->feature_id;
      $counter++;
    }

    // Get analysis information including 'Time', 'Name', and 'DB Settings'.
    $query2 = $this->connection->select('1:analysis', 'A');
    $query2->join('1:analysisprop', 'AP', '[A].analysis_id = [AP].analysis_d');
    $query2->join('1:cvterm', 'T', '[AP].type_id = [T].cvterm_id');
    $query2->join('1:cv', 'V', '[T].cv_id = [V].cv_id');
    $query2->addFields('A', ['value', 'name', 'timeexecuted']);
    $query2->condition('A.analysis_id', $analysis_id, '=');
    $query2->condition('T.name', 'analysis_blast_settings', '=');
    $query2->condition('V.name', 'local', '=');
    $analysis = $query2->execute()->fetchObject();

    // Parse the blast settings.
    $blastsettings = explode("|", $analysis->value);
    $db_id = $blastsettings[0];

    // Get the xml description parser using db_id.
    $query3 = $this->connection->select('1:tripal_analysis_blast', 'T');
    $query3->addFields('T', ['*']);
    $query3->condition('T.db_id', $db_id, '=');
    $parser = $query3->execute()->fetchObject();

    $db_name = $parser->displayname;
    $is_genbank = $parser->genbank_style;
    $regex_hit_id = $parser->regex_hit_id;
    $regex_hit_def = $parser->regex_hit_def;
    $regex_hit_accession = $parser->regex_hit_accession;
    $regex_hit_organism = $parser->regex_hit_organism;
    $hit_organism = $parser->hit_organism;

    // Set a default description parser if regular expressions have not
    // been specified.
    if (!$regex_hit_id) {
      $regex_hit_id = '/^(.*?)\s.*$/';
    }
    else {
      $regex_hit_id = '/' . $regex_hit_id . '/';
    }
    if (!$regex_hit_def) {
      $regex_hit_def = '/^.*?\s(.*)$/';
    }
    else {
      $regex_hit_def = '/' . $regex_hit_def . '/';
    }
    if (!$regex_hit_accession) {
      $regex_hit_accession = '/^(.*?)\s.*$/';
    }
    else {
      $regex_hit_accession = '/' . $regex_hit_accession . '/';
    }
    if ($regex_hit_organism) {
      $regex_hit_organism = '/' . $regex_hit_accession . '/';
    }
    if (!$hit_organism) {
      $hit_organism = 'Unknown';
    }

    // $interval = intval($counter * 0.01);
    for ($i = 0; $i < $counter; $i++) {
      $query4 = $this->connection->select('1:analysisfeatureprop', 'AFP');
      $query4->join('1:analysisfeature', 'AF', '[AFP].analysisfeature_id = [AF].analysisfeature_id');
      $query4->join('1:cvterm', 'T', '[AFP].type_id = [T].cvterm_id');
      $query4->join('1:cv', 'V', '[T].cv_id = [V].cv_id');
      $query4->condition('AF.analysis_id', $analysis_id, '=');
      $query4->condition('AF.feature_id', $feature_id, '=');
      $query4->condition('T.name', 'analysis_blast_output_iteration_hits', '=');
      $query4->condition('V.name', 'local', '=');
      $query4->addField('AFP', 'value', 'value');
      $xml = $query4->execute()->fetchField();
      $xml_output = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);

      $iteration = '';
      // New XML file parser has added the feature name within
      // <Iteration_query-def> tags.
      if ($xml_output->getName() == 'Iteration') {
        $query = "";
        foreach ($xml_output->children() as $xml_tag) {
          if ($xml_tag->getName() == 'Iteration_query-def') {
            // Here we show the feature name again to check if
            // we pull the correct data.
            $query = $xml_tag;
          }
          elseif ($xml_tag->getName() == 'Iteration_hits') {
            $iteration = $xml_tag;
          }
        }
        // This is for the file parsed by the old parser.
      }
      else {
        $iteration = $xml_output;
      }

      $number_hits = 0;
      foreach ($iteration->children() as $hits) {
        $number_hits++;
      }

      $query = explode(' ', $query);
      $query = $query[0];
      if ($number_hits == 0) {
        continue;
      }

      // Now run through the blast hits/hsps of this iteration
      // and generate the rows of the table.
      foreach ($iteration->children() as $hits) {
        $hit_count++;
        foreach ($hits->children() as $hit) {
          $best_evalue = 0;
          $best_score = 0;
          $best_identity = 0;
          $best_len = 0;
          $element_name = $hit->getName();
          if ($element_name == 'Hit_id') {
            // If parsing "name, acc, desc" from three tags (1/3)
            if ($is_genbank) {
              $hit_name = $hit;
            }
          }
          elseif ($element_name == 'Hit_def') {
            if ($is_genbank) {
              $description = $hit;
            }
            else {
              $accession = preg_replace($regex_hit_accession, "$1", $hit);
              $hit_name = preg_replace($regex_hit_id, "$1", $hit);
              $description = preg_replace($regex_hit_def, "$1", $hit);
              if ($regex_hit_organism) {
                $hit_organism = preg_replace($regex_hit_organism, "$1", $hit);
              }
            }
          }
          elseif ($element_name == 'Hit_accession') {
            // If parsing "name, acc, desc" from three tags (3/3)
            if ($is_genbank) {
              $accession = $hit;
            }
            // Now run through each HSP for this hit.
          }
          elseif ($element_name == 'Hit_hsps') {
            foreach ($hit->children() as $hsp) {
              foreach ($hsp->children() as $hsp_info) {
                $element_name = $hsp_info->getName();
                if ($element_name == 'Hsp_num') {
                  $hsp_num = $hsp_info;
                }
                if ($element_name == 'Hsp_bit-score') {
                  $hsp_bit_score = $hsp_info;
                }
                if ($element_name == 'Hsp_score') {
                  $hsp_score = $hsp_info;
                  // Use the first evalue for this set of HSPs
                  // as the best evalue. This get's shown as
                  // info for the overall match.
                  if (!$best_score) {
                    $best_score = $hsp_score;
                  }
                }
                if ($element_name == 'Hsp_evalue') {
                  $hsp_evalue = $hsp_info;
                  // Use the first evalue for this set of HSPs
                  // as the best evalue. This get's shown as
                  // info for the overall match.
                  if (!$best_evalue) {
                    $best_evalue = $hsp_evalue;
                  }
                }
                if ($element_name == 'Hsp_query-from') {
                  $hsp_query_from = $hsp_info;
                }
                if ($element_name == 'Hsp_query-to') {
                  $hsp_query_to = $hsp_info;
                }
                if ($element_name == 'Hsp_hit-from') {
                  $hsp_hit_from = $hsp_info;
                }
                if ($element_name == 'Hsp_hit-to') {
                  $hsp_hit_to = $hsp_info;
                }
                if ($element_name == 'Hsp_query-frame') {
                  $hsp_query_frame = $hsp_info;
                }
                if ($element_name == 'Hsp_identity') {
                  $hsp_identity = $hsp_info;
                  // Use the first evalue for this set of HSPs
                  // as the best evalue. This get's shown as
                  // info for the overall match.
                  if (!$best_identity) {
                    $best_identity = $hsp_identity;
                  }
                }
                if ($element_name == 'Hsp_positive') {
                  $hsp_positive = $hsp_info;
                }
                if ($element_name == 'Hsp_align-len') {
                  $hsp_align_len = $hsp_info;
                  // Use the first evalue for this set of HSPs
                  // as the best evalue. This get's shown as
                  // info for the overall match.
                  if (!$best_len) {
                    $best_len = $hsp_align_len;
                  }
                }
                if ($element_name == 'Hsp_qseq') {
                  $hsp_qseq = $hsp_info;
                }
                if ($element_name == 'Hsp_hseq') {
                  $hsp_hseq = $hsp_info;
                }
                if ($element_name == 'Hsp_midline') {
                  $hsp_midline = $hsp_info;
                }
              }
            }
          }
        }

        // Get analysisfeature_id.
        $sql = "SELECT analysisfeature_id FROM {1:analysisfeature} WHERE analysis_id = :analysis_id AND feature_id = :feature_id";
        $af_id = $this->connection->query($sql, [':analysis_id' => $analysis_id, ':feature_id' => $feature_set[$i]])->fetchField();

        // Get type_id.
        $sql = "SELECT cvterm_id FROM {1:cvterm} WHERE name = :name AND cv_id = (SELECT cv_id FROM {1:cv} WHERE name = 'local')";
        $type_id = $this->connection->query($sql, [':name' => 'analysis_blast_besthit_query'])->fetchField();

        $sql_test = "SELECT analysisfeatureprop_id FROM {1:analysisfeatureprop} WHERE analysisfeature_id = :analysisfeature_id AND type_id = :type_id";
        $test_afpid = $this->connection->query($sql_test, ['analysisfeature_id:' => $af_id, ':type_id' => $type_id])->fetchField();

        // Insert only if this blast query not exists.
        if (!$test_afpid) {
          $afp_sql = "INSERT INTO {1:analysisfeatureprop} (analysisfeature_id, type_id, value, rank) VALUES (:analysisfeature_id, :type_id, :rank, 0)";

          // $query;
          $this->connection->query($afp_sql, [
            ':analysisfeature_id' => $af_id,
            ':type_id' => $type_id,
            ':rank' => $query,
          ]);

          // $hit_name;
          $type_id = $this->connection->query($sql, 'analysis_blast_besthit_match')->fetchField();
          $this->connection->query($afp_sql, [
            ':analysisfeature_id' => $af_id,
            ':type_id' => $type_id,
            'rank' => $hit_name,
          ]);

          // $description;
          $type_id = $this->connection->query($sql, [':name' => 'analysis_blast_besthit_description'])->fetchField();
          $this->connection->query($afp_sql, [
            ':analysisfeature_id' => $af_id,
            ':type_id' => $type_id,
            ':rank' => $description,
          ]);

          // $best_evalue;
          $type_id = $this->connection->query($sql, [':name' => 'analysis_blast_besthit_evalue'])->fetchField();
          $e_digit = explode("e-", $best_evalue);
          if (count($e_digit) == 2) {
            $evalue_shown = number_format($e_digit[0], 1);
            $best_evalue = $evalue_shown . "e-" . $e_digit[1];
          }
          $this->connection->query($afp_sql, [
            ':analysisfeature_id' => $af_id,
            ':type_id' => $type_id,
            ':rank' => $best_evalue,
          ]);

          // $best_identity;
          $type_id = $this->connection->query($sql, [':name' => 'analysis_blast_besthit_identity'])->fetchField();
          $percent_identity = number_format($best_identity / $best_len * 100, 1);
          $this->connection->query($afp_sql, [
            ':analysisfeature_id' => $af_id,
            ':type_id' => $type_id,
            ':rank' => $percent_identity,
          ]);

          // $best_len;
          $type_id = $this->connection->query($sql, [':name' => 'analysis_blast_besthit_length'])->fetchField();
          $this->connection->query($afp_sql, [
            ':analysisfeature_id' => $af_id,
            ':type_id' => $type_id,
            ':rank' => $best_len,
          ]);

          // Otherwise, update all instead.
        }
        else {
          $afp_sql = "UPDATE {1:analysisfeatureprop} SET analysisfeature_id = :analysisfeature_id, type_id = :type_id, value = :value, rank = 0 WHERE analysisfeatureprop_id = :analysisfeatureprop_id";

          // $query;
          $this->connection->query($afp_sql, [
            ':analysisfeature_id' => $af_id,
            ':type_id' => $type_id,
            ':value' => $query,
            ':analysisfeatureprop_id' => $test_afpid,
          ]);

          // $hit_name;
          $type_id = $this->connection->query($sql, [':name' => 'analysis_blast_besthit_match'])->fetchField();
          $test_afpid = $this->connection->query($sql_test, [':analysisfeature_id' => $af_id, ':type_id' => $type_id])->fetchField();
          $this->connection->query($afp_sql, [
            ':analysisfeature_id' => $af_id,
            ':type_id' => $type_id,
            ':value' => $hit_name,
            ':analysisfeatureprop_id' => $test_afpid,
          ]);

          // $description;
          $type_id = $this->connection->query($sql, [':name' => 'analysis_blast_besthit_description'])->fetchField();
          $test_afpid = $this->connection->query($sql_test, [':analysisfeature_id' => $af_id, ':type_id' => $type_id])->fetchField();
          $this->connection->query($afp_sql, [
            ':analysisfeature_id' => $af_id,
            ':type_id' => $type_id,
            ':value' => $description,
            ':analysisfeatureprop_id' => $test_afpid,
          ]);

          // $best_evalue;
          $type_id = $this->connection->query($sql, [':name' => 'analysis_blast_besthit_evalue'])->fetchField();
          $test_afpid = $this->connection->query($sql_test, [':analysisfeature_id' => $af_id, ':type_id' => $type_id])->fetchField();
          $e_digit = explode("e-", $best_evalue);
          if (count($e_digit) == 2) {
            $evalue_shown = number_format($e_digit[0], 1);
            $best_evalue = $evalue_shown . "e-" . $e_digit[1];
          }
          $this->connection->query($afp_sql, [
            ':analysisfeature_id' => $af_id,
            ':type_id' => $type_id,
            ':value' => $best_evalue,
            ':analysisfeatureprop_id' => $test_afpid,
          ]);

          // $best_identity;
          $type_id = $this->connection->query($sql, [':name' => 'analysis_blast_besthit_identity'])->fetchField();
          $test_afpid = $this->connection->query($sql_test, [':analysisfeature_id' => $af_id, ':type_id' => $type_id])->fetchField();
          $percent_identity = number_format($best_identity / $best_len * 100, 1);
          $this->connection->query($afp_sql, [
            ':analysisfeature_id' => $af_id,
            ':type_id' => $type_id,
            ':value' => $percent_identity,
            ':analysisfeatureprop_id' => $test_afpid,
          ]);

          // $best_len;
          $type_id = $this->connection->query($sql, [':name' => 'analysis_blast_besthit_length'])->fetchField();
          $test_afpid = $this->connection->query($sql_test, [':analysisfeature_id' => $af_id, ':type_id' => $type_id])->fetchField();
          $this->connection->query($afp_sql, [
            ':analysisfeature_id' => $af_id,
            ':type_id' => $type_id,
            ':value' => $best_len,
            ':analysisfeatureprop_id' => $test_afpid,
          ]);
        }

        break;
      }
    }
  }

}
