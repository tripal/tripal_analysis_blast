<?php

namespace Drupal\tripal_analysis_blast\Plugin\TripalImporter;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tripal\TripalImporter\Attribute\TripalImporter;
#use Drupal\tripal\TripalImporter\Interfaces\TripalImporterInterface;
#use Drupal\tripal_chado\Controller\ChadoGenericAutocompleteController;
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

    $chado = $this->getChadoConnection();
    // Always call the parent form to ensure Chado is handled properly.
    $form = [];
    $form = parent::form($form, $form_state);

    $form_state_values = $form_state->getValues();
    $form_state_input = $form_state->getUserInput();

    // Default values can come in the following ways:
    // If we are reconstructing the form from a failed validation or ajax
    // callback then use the $form_state_values values.
    // If we are rebuilding the form from after submission (from an ajax
    // call) then the values are in the $form_state_input array.
    $blast = NULL;
    $blastdb = $form_state_values['blastdb'] ?? $form_state_input['blastdb'] ?? NULL;
    $blastfile_ext = $form_state_values['blastfile_ext'] ?? $form_state_input['blastfile_ext'] ?? '';
    $query_re = $form_state_values['query_re'] ?? $form_state_input['query_re'] ?? '';
    $query_type = $form_state_values['query_type'] ?? $form_state_input['query_type'] ?? '';
    $query_uniquename = $form_state_values['query_uniquename'] ?? $form_state_input['query_uniquename'] ?? 0;
    $is_concat = $form_state_values['is_concat'] ?? $form_state_input['is_concat'] ?? 0;
    $search_keywords = '';
    $blastjob = FALSE;

    $form['db_options'] = $this->getDbSelectElement($blastdb);

    $form['blastfile_ext'] = [
      '#title' => $this->t('Blast XML file extension'),
      '#type' => 'textfield',
      '#description' => $this->t('If a directory is provide for the blast file setting above,
        then a file extension can be provided here. Files with this extension in the directory will be parsed.
        If no extension is provided then files with a .xml extension will be parsed within the directory.
        Please provide the extension without the preceeding period (e.g. "out" rather than ".out"'),
      '#default_value' => $blastfile_ext,
    ];

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
    $chado_connection = $this->getChadoConnection();
    // Get the list of databases.
    $query = $chado_connection->select('1:db', 'D');
    $query->fields('D', ['db_id', 'name']);
    $query->orderBy('D.name');
    $dbs = [];
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
          '@add_database' => 'todo',
          '@settings_page' => 'todo',
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
