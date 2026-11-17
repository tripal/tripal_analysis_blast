<?php

namespace Drupal\tripal_analysis_blast\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
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

    // Formatter configurations.
    $form['display'] = [
      '#type' => 'details',
      '#title' => $this->t('Display'),
      '#description' => $this->t('Settings for how blast results are displayed'),
      '#open' => TRUE,
    ];

    $formatter_pager_items = $config->get('tripal_analysis_blast_config_display.formatter_pager_items') ?? 10;
    $form['display']['formatter_pager_items'] = [
      '#type' => 'number',
      '#title' => $this->t('Blast hits per page'),
      '#min' => 1,
      '#description' => $this->t('The number of blast hits to show at one time.
          If there are more than this number, then a pager is used.'),
      '#default_value' => $formatter_pager_items,
    ];

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

    $database = $config->get('tripal_analysis_blast_config_databases.database.id') ?? '';
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

    $selected_database = $form_state->getValue('database', $database);
    $form['databases']['database_settings'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'tripal-analysis-blast-configuration-database',
      ],
    ];

    if (!empty($selected_database)) {
      $form['databases']['database_settings']['hit_name_regex'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Hit Name Regex'),
        '#default_value' => $config->get('tripal_analysis_blast_config_databases.hit_name_regex') ?? '',
      ];

      $form['databases']['database_settings']['description_regex'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Description Regex'),
        '#default_value' => $config->get('tripal_analysis_blast_config_databases.description_regex') ?? '',
      ];

      $form['databases']['database_settings']['organism_regex'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Organism Regex'),
        '#default_value' => $config->get('tripal_analysis_blast_config_databases.organism_regex') ?? '',
      ];
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
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->configFactory->getEditable(static::SETTINGS);

    // Display configurations.
    $formatter_pager_items = $form_state->getValue('formatter_pager_items');
    $config->set('tripal_analysis_blast_config_display.formatter_pager_items', $formatter_pager_items);

    // Database configurations.
    $database = $form_state->getValue('database');
    $config->set('tripal_analysis_blast_config_databases.database.id', $database);

    // Set defined variables.
    $config->save();

    return parent::submitForm($form, $form_state);
  }

  /**
   * Generates a list of chado databases suitable for a select element.
   *
   * @return array
   *   The list of databases suitable to use as values for a form select.
   */
  protected function getDatabases(): array {
    $query = $this->chado_connection->select('1:db', 'db');
    $query->fields('db', ['db_id', 'name']);
    $query->condition('[db].urlprefix', '', '!=');
    $query->isNotNull('[db].urlprefix');
    $query->orderBy('db.name');
    $results = $query->execute();
    $select_arr = [];
    foreach ($results as $result) {
      $name_with_id = $result->name . ' (' . $result->db_id . ')';
      $select_arr[$result->db_id] = $name_with_id;
    }
    return $select_arr;
  }

}
