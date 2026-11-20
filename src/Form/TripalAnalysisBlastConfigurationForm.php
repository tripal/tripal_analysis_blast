<?php

namespace Drupal\tripal_analysis_blast\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\tripal_chado\Controller\ChadoOrganismFormElementController;
use Drupal\tripal_chado\Database\ChadoConnection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines TripalAnalysisBlastConfigurationForm class.
 *
 * Constructs admin pages configuration page.
 * Page is laid out in tabs/task.
 *
 * @see tripal_analysis_blast.links.tasks.yml
 */
class TripalAnalysisBlastConfigurationForm extends ConfigFormBase {

  /**
   * Defines the name used to store settings for this module.
   */
  const SETTINGS = 'tripal_analysis_blast.settings';

  /**
   * A Database query interface for querying Chado using Tripal DBX.
   *
   * @var Drupal\tripal_chado\Database\ChadoConnection
   */
  protected ChadoConnection $chado_connection;

  /**
   * Constructs a TripalAnalysisBlastConfigurationForm.
   *
   * @param Drupal\tripal_chado\Database\ChadoConnection $chado_connection
   *   The chado connection used to query chado.
   */
  public function __construct(
    ChadoConnection $chado_connection,
  ) {
    $this->chado_connection = $chado_connection;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tripal_chado.database'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'analysis_blast_ui_configuration';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      static::SETTINGS,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Configuration/module variables.
    $config = $this->config(static::SETTINGS);

    // Attach css for this form.
    $form['#attached']['library'][] = 'tripal_analysis_blast/tripal-analysis-blast-configuration';

    // If the database select has changed values, clear
    // existing values in that part of the form.
    // We can't do this in the ajax callback.
    $trigger = $form_state->getTriggeringElement();
    if ($trigger && $trigger['#name'] === 'database') {
      $fields_to_clear = [
        'gb_style_parser',
        'displayname',
        'hit_id',
        'hit_def',
        'hit_accession',
        'hit_organism_re',
        'hit_organism',
      ];
      $user_input = $form_state->getUserInput();
      foreach ($fields_to_clear as $field) {
        // Clear processed values.
        $form_state->unsetValue($field);
        // Clear raw user input so Drupal doesn't repopulate the fields.
        unset($user_input[$field]);
      }
      $form_state->setUserInput($user_input);
    }

    // Get default values for the form elements.
    $database = $form_state->getValue('database', 0);

    // Values stored in the public.tripal_analysis_blast table.
    $stored_values = $this->selectTripalAnalysisBlast($database);
    $gb_style_parser = $form_state->getValue('gb_style_parser', $stored_values['genbank_style'] ?? 0);
    $displayname = $form_state->getValue('displayname', $stored_values['displayname'] ?? '');
    $hit_id = $form_state->getValue('hit_id', $stored_values['regex_hit_id'] ?? '');
    $hit_def = $form_state->getValue('hit_def', $stored_values['regex_hit_def'] ?? '');
    $hit_accession = $form_state->getValue('hit_accession', $stored_values['regex_hit_accession'] ?? '');
    $hit_organism_re = $form_state->getValue('hit_organism_re', $stored_values['regex_hit_organism'] ?? '');
    $hit_organism = $form_state->getValue('hit_organism', $stored_values['hit_organism'] ?? '');

    // Database import configurations.
    $form['databases'] = [
      '#type' => 'details',
      '#title' => $this->t('Databases'),
      '#description' => $this->t('You may customize how blast results are parsed and displayed
          to the end-user. Select a database from the list to show the customizable fields.
          The fields specify how to extract hit names, descriptions, database accessions, and
          organisms from the blast results.'),
      '#open' => TRUE,
    ];

    $form['databases']['database'] = [
      '#type' => 'select',
      '#title' => $this->t('Chado Database'),
      '#description' => $this->t('Select a database from the list. Only chado databases with a defined urlprefix are shown.'),
      '#options' => $this->getDatabases(),
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $database,
      '#ajax' => [
        'callback' => '::databaseChangedCallback',
        'wrapper' => 'tripal-analysis-blast-configuration-database',
        'event' => 'change',
      ],
    ];

    $form['databases']['database_settings'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'tripal-analysis-blast-configuration-database',
      ],
    ];

    if (!empty($database)) {
      $form['databases']['database_settings']['displayname'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Title for the blast analysis'),
        '#default_value' => $displayname,
      ];

      $form['databases']['database_settings']['gb_style_parser'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Use Genbank style parser'),
        '#description' => $this->t('This will override all regular expression settings for the selected database.'),
        '#default_value' => $gb_style_parser,
        '#ajax' => [
          'callback' => '::genbankStyleChangedCallback',
          'wrapper' => 'tripal-analysis-blast-configuration-genbank-style',
          'event' => 'change',
        ],
      ];
    }

    $form['databases']['database_settings']['database_genbank_style'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'tripal-analysis-blast-configuration-genbank-style',
      ],
    ];

    if (!empty($database) && empty($gb_style_parser)) {
      $form['databases']['database_settings']['database_genbank_style']['hit_id'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Regular expression for Hit Name'),
        '#description' => $this->t('A regular expression that will extract the name of the hit sequence from the BLAST results.'),
        '#default_value' => $hit_id,
      ];

      $form['databases']['database_settings']['database_genbank_style']['hit_def'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Regular expression for Hit Description'),
        '#default_value' => $hit_def,
      ];

      $form['databases']['database_settings']['database_genbank_style']['hit_accession'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Regular expression for Hit Accession'),
        '#default_value' => $hit_accession,
      ];

      $form['databases']['database_settings']['database_genbank_style']['hit_organism_re'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Regular expression for Organism'),
        '#default_value' => $hit_organism_re,
      ];

      // Get the organism select element or auto-complete element and
      // its validator.
      $form['databases']['database_settings']['database_genbank_style']['organism_id'] = ChadoOrganismFormElementController::getFormElement([], $hit_organism, []);
      $form['databases']['database_settings']['database_genbank_style']['organism_id']['#title'] = t('Organism');
      $form['databases']['database_settings']['database_genbank_style']['organism_id']['#description'] = $this->t(
        'If the organism for the match cannot be determined using a regular
        expression but the database only contains a single organism, then
        you may specify the organism here. If the organism cannot be
        determined using a regular expression then leave this field blank.');
      $form['databases']['database_settings']['database_genbank_style']['organism_id']['#element_validate']
        = [[ChadoOrganismFormElementController::class, 'validateAutocomplete']];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * Ajax callback for database selection.
   */
  public function databaseChangedCallback(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
    return $form['databases']['database_settings'];
  }

  /**
   * Ajax callback for genbank style checkbox.
   */
  public function genbankStyleChangedCallback(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
    return $form['databases']['database_settings']['database_genbank_style'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    // Validate the regular expressions.
    $fields = ['hit_id', 'hit_def', 'hit_accession', 'hit_organism_re'];
    foreach ($fields as $field) {
      if ($values[$field] ?? '') {
        $result = @preg_match($values[$field], '');
        if ($result === FALSE) {
          $form_state->setErrorByName($field, 'The regular expression is not valid.');
        }
        elseif (!preg_match('/.\(.+\)./', $values[$field])) {
          $form_state->setErrorByName($field, 'The regular expression must contain parentheses to capture a value.');
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    if ($values['database']) {
      // Assemble the database config into an array matching the db table
      // public.tripal_analysis_blast and save it there.
      $tripal_analysis_blast_values = [
        'db_id' => $values['database'],
        'displayname' => $values['displayname'],
        'regex_hit_id' => $values['hit_id'],
        'regex_hit_def' => $values['hit_def'],
        'regex_hit_accession' => $values['hit_accession'],
        'regex_hit_organism' => $values['hit_organism_re'],
        'hit_organism' => $values['organism_id'],
        'genbank_style' => $values['gb_style_parser'],
      ];
      $this->upsertTripalAnalysisBlast($tripal_analysis_blast_values);
    }

    return parent::submitForm($form, $form_state);
  }

  /**
   * Retrieves one record from public.tripal_analysis_blast.
   *
   * @param int|string $db_id
   *   The db_id value for the desired record. Might be zero.
   *
   * @return array
   *   The retrieved record, or empty array if no record matches.
   */
  protected function selectTripalAnalysisBlast(int|string $db_id): array {
    $result = [];
    if ($db_id) {
      $query = $this->chado_connection->select('0:tripal_analysis_blast', 'B');
      $query->condition('db_id', $db_id, '=');
      $query->fields('B', []);
      // Converts FALSE to empty array.
      $result = $query->execute()->fetchAssoc() ?: [];
    }
    return $result;
  }

  /**
   * Upsert one record in public.tripal_analysis_blast.
   *
   * @param array $values
   *   An array of values with keys matching the table column names.
   *   If no record matching $values['db_id'] exists, it is inserted,
   *   otherwise the record is updated, except in the case where all
   *   fields other than db_id are empty, in which case the record is
   *   deleted.
   *
   * @return void
   *   No return value.
   */
  protected function upsertTripalAnalysisBlast(array $values): void {
    $db_id = $values['db_id'];
    $existing = $this->selectTripalAnalysisBlast($db_id);
    if ($existing) {
      $has_data = FALSE;
      foreach ($values as $key => $value) {
        if ($value && ($key != 'db_id')) {
          $has_data = TRUE;
        }
      }
      if ($has_data) {
        // Update existing record.
        unset($values['db_id']);
        $query = $this->chado_connection->update('0:tripal_analysis_blast');
        $query->fields($values);
        $query->condition('db_id', $db_id, '=');
        $query->execute();
      }
      else {
        // Drop existing record.
        $query = $this->chado_connection->delete('0:tripal_analysis_blast');
        $query->condition('db_id', $db_id, '=');
        $query->execute();
      }
    }
    else {
      // Insert new record.
      $query = $this->chado_connection->insert('0:tripal_analysis_blast');
      $query->fields($values);
      $query->execute();
    }
  }

  /**
   * Generates a list of chado databases suitable for a select element.
   *
   * Databases configured for this module will be listed first, with
   * a "[configured]" suffix.
   *
   * @return array
   *   The list of databases suitable to use as values for a form select.
   */
  protected function getDatabases(): array {
    $query = $this->chado_connection->select('1:db', 'D');
    $query->leftJoin('0:tripal_analysis_blast', 'B', '[D].db_id=[B].db_id');
    $query->addField('D', 'db_id', 'db_db_id');
    $query->addField('D', 'name', 'name');
    $query->addField('B', 'db_id', 'configured');
    $query->condition('[D].urlprefix', '', '!=');
    $query->isNotNull('[D].urlprefix');
    $query->orderBy('D.name');
    $results = $query->execute();
    $configured_arr = [];
    $select_arr = [];
    foreach ($results as $result) {
      $name = $result->name;
      if ($result->configured) {
        $name .= $this->t(' [configured]');
        $configured_arr[$result->db_db_id] = $name;
      }
      else {
        $select_arr[$result->db_db_id] = $name;
      }
    }
    return $configured_arr + $select_arr;
  }

}
