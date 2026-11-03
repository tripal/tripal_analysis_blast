<?php

namespace Drupal\tripal_analysis_blast\Plugin\TripalImporter;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tripal\TripalImporter\Attribute\TripalImporter;
#use Drupal\tripal\TripalImporter\Interfaces\TripalImporterInterface;
#use Drupal\tripal_chado\Controller\ChadoCVTermAutocompleteController;
#use Drupal\tripal_chado\Controller\ChadoGenericAutocompleteController;
#use Drupal\tripal_chado\Controller\ChadoOrganismFormElementController;
use Drupal\tripal_chado\TripalImporter\ChadoImporterBase;

/**
 * Blast result importer implementation of the TripalImporterBase.
 */
#[TripalImporter(
  id: 'blast_importer',
  label: new TranslatableMarkup('Chado BLAST XML results loader'),
  description: new TranslatableMarkup('Import a BLAST XML file into Chado'),
  file_types: [
    'xml',
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
   * {@inheritdoc}
   */
  public function form($form, &$form_state) {

    $form = [];

    // The parent adds a chado schema select element.
    $form = parent::form($form, $form_state);

    $form_state_values = $form_state->getValues();
    $form_state_input = $form_state->getUserInput();

    // Default values can come in the following ways:
    // If we are reconstructing the form from a failed validation or ajax
    // callback then use $form_state_values.
    // If we are rebuilding the form from after submission (from an ajax
    // call) then the values are in the $form_state_input array.
#    $blast = NULL;
    $blastdb = $form_state_values['blastdb'] ?? $form_state_input['blastdb'] ?? NULL;
    $blastfile_ext = $form_state_values['blastfile_ext'] ?? $form_state_input['blastfile_ext'] ?? '';
    $query_re = $form_state_values['query_re'] ?? $form_state_input['query_re'] ?? '';
    $query_type = $form_state_values['query_type'] ?? $form_state_input['query_type'] ?? '';
    $query_uniquename = $form_state_values['query_uniquename'] ?? $form_state_input['query_uniquename'] ?? 0;
    $is_concat = $form_state_values['is_concat'] ?? $form_state_input['is_concat'] ?? 0;
#    $search_keywords = '';
#    $blastjob = FALSE;

    $form['db_options'] = $this->getDbSelectElement($blastdb);

    $form['blastfile_ext'] = [
      '#title' => $this->t('Blast XML file extension'),
      '#type' => 'textfield',
      '#description' => $this->t('If a directory is provided for the blast file setting above,
        then a file extension can be provided here. Files with this extension in the directory will be parsed.
        If no extension is provided, then files with a .xml extension will be parsed within the directory.
        Please provide the extension without the preceeding period (e.g. "out" rather than ".out"'),
      '#default_value' => $blastfile_ext,
    ];

    $form['is_concat'] = [
      '#title' => $this->t('Is the XML file concatenated?'),
      '#type' => 'checkbox',
      '#description' => $this->t('Is the XML file a set of concatenated XML results?
      Such is the case, for instance, if <a href="https://www.blast2go.com/">Blast2GO</a> was used to
      generate the blast results.
      If NCBI BLAST was used with output in XML then this option should not be checked.'),
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
      This option is is only required when the query does not identically match a feature
      in the database.'),
      '#default_value' => $query_re,
    ];

    // Get the sequence ontology CV ID.
    $cv_results = $this->connection->select('1:cv', 'cv')
      ->fields('cv')
      ->condition('name', 'sequence')
      ->execute();
    $cv_id = $cv_results->fetchObject()->cv_id;

    $form['query_type'] = [
      '#title' => $this->t('Query Type'),
      '#type' => 'textfield',
      '#description' => $this->t('Please enter the Sequence Ontology term that describes
      the query sequences used for blasting.
      This is only necessary if two or more sequences have the same name.'),
      '#default_value' => $query_type,
      '#autocomplete_route_name' => 'tripal_chado.cvterm_autocomplete',
      '#autocomplete_route_parameters' => ['count' => 5, 'cv_id' => $cv_id],
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
   * @param int|null $blastdb
   *   The default db_id value for the select element.
   *
   * @return array
   *   The element render array.
   */
  protected function getDbSelectElement(?int $blastdb): array {
    // Get the list of databases.
    $query = $this->connection->select('1:db', 'D');
    $query->fields('D', ['db_id', 'name']);
    $query->addExpression('LOWER(name)', 'lower_name');
    $query->orderBy('lower_name');
    $dbs = ['' => '- Select -'];
    $results = $query->execute();
    while ($db_obj = $results->fetchObject()) {
      $dbs[$db_obj->db_id] = $db_obj->name;
    }

    $element = [
      '#title' => $this->t('Database'),
      '#type' => 'select',
      '#description' => $this->t('The database used for the blast analysis.
        If the database does not appear in this list, please @add_database.
        Each database may have a different format for each match.
        This blast module will attempt to extract the match name, match accession, and organism from each match.
        To ensure the parser is able to properly extract this information,
        please set the proper regular expression values on the @settings_page.
        Databases from NCBI have a built-in parser. On the Blast Settings page,
        simply click the box "Use Genebank style parser"',
        [
          '@add_database' => '@todo /admin/tripal/terms/chado_db',
          '@settings_page' => '@todo',
        ]),
      '#options' => $dbs,
      '#default_value' => $blastdb,
    ];
    return $element;

  }

  /**
   * {@inheritdoc}
   */
  public function formValidate($form, &$form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function run() {
  }

  /**
   * {@inheritdoc}
   */
  public function formSubmit($form, &$form_state) {
  }

}
