<?php

namespace Drupal\tripal_analysis_blast\Plugin\Field\FieldType;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tripal\TripalField\Attribute\TripalFieldType;
use Drupal\tripal\Entity\TripalEntityType;
use Drupal\tripal_chado\Plugin\TripalStorage\ChadoStorage;
use Drupal\tripal_chado\TripalField\ChadoFieldItemBase;
use Drupal\tripal_chado\TripalStorage\ChadoIntStoragePropertyType;
use Drupal\tripal_chado\TripalStorage\ChadoTextStoragePropertyType;
use Drupal\tripal_chado\TripalStorage\ChadoVarCharStoragePropertyType;
use Drupal\tripal_analysis_blast\Plugin\TripalImporter\BlastImporter;

/**
 * Plugin implementation of the blast results field type.
 */
#[TripalFieldType(
  id: 'chado_blast_results',
  category: 'tripal_chado',
  label: new TranslatableMarkup('Chado BLAST Analysis Results'),
  description: new TranslatableMarkup('Results of a sequence database search using BLAST.'),
  default_widget: 'chado_blast_results_widget_default',
  default_formatter: 'chado_blast_results_formatter_default',
)]
class ChadoBlastResultsTypeDefault extends ChadoFieldItemBase {

  /**
   * The unique identifier of this field.
   *
   * NOTE: must match the id in the Attribute.
   *
   * @var string
   */
  public static $id = "chado_blast_results";

  /**
   * Flag to indicate a base column selector should be shown.
   *
   * @var bool
   *   When TRUE the ChadoFieldItemBase parent class will add a base column
   *   select list on the field configuration form. When FALSE, no selector
   *   will be available.
   */
  protected static $select_base_column = FALSE;

  /**
   * Valid column types to pass to the ChadoFieldItemBase parent class.
   *
   * @var array
   *   Columns of the types listed here will be added to the select list on
   *   the field configuration form.
   */
  protected static $valid_base_column_types = ['int', 'bigint', 'serial'];

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    $field_settings = parent::defaultFieldSettings();
    $field_settings['termIdSpace'] = 'format';
    $field_settings['termAccession'] = '1333';
    return $field_settings;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultStorageSettings() {
    $storage_settings = parent::defaultStorageSettings();

    $storage_settings['storage_plugin_id'] = 'chado_storage';
    $storage_settings['storage_plugin_settings']['base_table'] = 'feature';
    $storage_settings['storage_plugin_settings']['base_column'] = 'feature_id';

    return $storage_settings;
  }

  /**
   * {@inheritdoc}
   */
  public function storageSettingsForm(array &$form, FormStateInterface $form_state, $has_data) {
    $elements = parent::storageSettingsForm($form, $form_state, $has_data);
    // Partially fixes bug from issue #2568 if try to add to incompatible type.
    $elements ??= [];
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public static function tripalTypes($field_definition) {

    // Retrieve the storage settings.
    $entity_type_id = $field_definition->getTargetEntityTypeId();
    $storage_settings = $field_definition->getSetting('storage_plugin_settings');
    $base_table = $storage_settings['base_table'] ?? 'feature';
    $base_column = $storage_settings['base_column'] ?? 'feature_id';

    // This field only supports one table, so many items can be hardcoded.
    $base_pkey_col = 'feature_id';
    $hit_term = 'format:1333';

    $properties = [];

    // Define the base table record id.
    $properties[] = new ChadoIntStoragePropertyType($entity_type_id, self::$id, 'record_id', self::$record_id_term, [
      'action' => 'store_id',
      'drupal_store' => TRUE,
      'path' => $base_table . '.' . $base_pkey_col,
    ]);

    // This property will store the json encoded blast hit.
    $properties[] = new ChadoTextStoragePropertyType($entity_type_id, self::$id, 'hit_data', $hit_term, [
      'action' => 'function',
      'drupal_store' => TRUE,
      // __CLASS__ resolves to 'Drupal\tripal_analysis_blast\Plugin\Field\FieldType\FormatBlastResultsTypeItem'
      'namespace' => __CLASS__,
      'function' => 'getEncodedBlast',
    ]);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    $values = [];

    // Generate a random value to use as a sample.
    $values['record_id'] = 1;
    $values['hit_data'] = '';

    return $values;
  }

  /**
   * {@inheritDoc}
   *
   * @see \Drupal\tripal_chado\TripalField\ChadoFieldItemBase::isCompatible()
   */
  public function isCompatible(TripalEntityType $entity_type) : bool {
    // Get the base table for the content type.
    $base_table = $entity_type->getThirdPartySetting('tripal', 'chado_base_table');
    // This field only supports the chado.feature table.
    $compatible = ($base_table == 'feature');
    return $compatible;
  }

  /**
   * Retrieves blast hits and json encodes them.
   *
   * @param array $context
   *   Values that a callback function might need in order
   *   to calculate the field's final value.
   *
   * @return string
   *   Data encoded in json format.
   */
  public static function getEncodedBlast(array $context): string {

    // This will hold each of the tripalTypes values.
    $field_name = $context['field_name'];
    $delta = $context['delta'];
    $values = $context['values'][$field_name][$delta];

    // This retrieves the feature_id value.
    $record_id = $values['record_id']['value']->getValue();

    // This will retrieve the blast hit data and convert to json format.
    $db_id = NULL;
    $hits = self::tripalGetFeatureBlastResults($record_id, $context, $db_id);
    $json = Json::encode($hits);
    return $json;
  }

  /**
   * Get a blast result from the chado.analysisfeature_prop table.
   *
   * @param int $feature_id
   *   The feature_id of the feature
   * @param array $context
   *   The context array originally passed to the callback.
   * @param int|null $db_id
   *   Optional. Specify a database id. This is useful if a feature was
   *   blast'ed against multipe databases and you only need to retrieve
   *   results for a specific set.
   *
   * @return array
   *   An array of blast hit objects.
   */
  protected static function tripalGetFeatureBlastResults(int $feature_id, array $context, ?int $db_id = NULL): array {

    $chado_connection = \Drupal::service('tripal_chado.database');
    $entity_lookup_manager = \Drupal::service('tripal.tripal_entity.lookup');

    // Get the blast results stored as XML from the analysisfeatureprop table.
    // The type for the property is named 'analysis_blast_output_iteration_hits'
    // and is found in the 'local' controlled vocabulary (in tripal3 it was the
    // 'tripal' vocabulary. This CV term was added by this module.
    $query1 = $chado_connection->select('1:analysisfeature', 'AF');
    $query1->join('1:analysisfeatureprop', 'P', '[AF].feature_id = [P].analysisfeature_id');
    $query1->join('1:cvterm', 'T', '[P].type_id = [T].cvterm_id');
    $query1->join('1:cv', 'V', '[T].cv_id = [V].cv_id');
    $query1->join('1:analysis', 'A', '[AF].analysis_id = [A].analysis_id');
    $query1->join('1:feature', 'F', '[AF].feature_id = [F].feature_id');
    $query1->addField('AF', 'analysis_id', 'analysis_id');
    $query1->addField('P', 'analysisfeature_id', 'analysisfeature_id');
    $query1->addField('P', 'value', 'value');
    $query1->addField('F', 'name', 'feature_name');
    $query1->addField('F', 'uniquename', 'feature_uniquename');
    $query1->fields('A', ['name', 'description', 'program', 'programversion', 'algorithm', 'sourcename', 'sourceversion', 'sourceuri', 'timeexecuted']);
    $query1->condition('T.name', 'analysis_blast_output_iteration_hits', '=');
    $query1->condition('V.name', 'local', '=');
    $query1->condition('AF.feature_id', $feature_id, '=');
    $blast_results = $query1->execute()->fetchAll();
    if (!$blast_results) {
      return [];
    }

    // Retrieve the data to be provided to the formatter.
    $blast_object_array = [];
    $blast_object_counter = 0;
    foreach ($blast_results as $analysisfeatureprop) {
      // Get the blast XML for this feature.
      $blast_xml = $analysisfeatureprop->value;
      $analysisfeature_id = $analysisfeatureprop->analysisfeature_id;
      $analysis_id = $analysisfeatureprop->analysis_id;

      // Get the analysis object.
      $query2 = $chado_connection->select('1:analysis', 'A');
      $query2->fields('A', []);
      $query2->condition('A.analysis_id', $analysis_id, '=');
      $analysis_object = $query2->execute()->fetchObject();

      // Get the analysis entity_id if it is published.
      $analysis_entity_id = $entity_lookup_manager->getEntityId(
        $analysis_id,
        NULL,
        NULL,
        'analysis'
      );
      $analysis_object->entity_id = $analysis_entity_id;

      // Get the blast_hit_data record for this analysis feature.
      $query3 = $chado_connection->select('1:blast_hit_data', 'B');
      $query3->fields('B', ['db_id']);
      $query3->condition('B.analysisfeature_id', $analysisfeature_id, '=');
      $blast_settings = $query3->execute()->fetchObject();

      $adb_id = NULL;
      if ($blast_settings) {
        $adb_id = $blast_settings->db_id;
      }

      // If the callee specified a database to show then we want to check that
      // with the database id of the analysis we're looking at. If they
      // don't match then skip this blast. If a database id was not specified
      // then continue.
      if ($db_id && $adb_id != $db_id) {
        continue;
      }

      // Analysis db will be null if no blast hits for this feature.
      if ($adb_id) {
        // Get the database object.
        $query4 = $chado_connection->select('1:db', 'D');
        $query4->fields('D', []);
        $query4->condition('D.db_id', $adb_id, '=');
        $db_object = $query4->execute()->fetchObject();

        // Parse the XML and add it to the array of blast results to
        // be returned.
        $blast_object = BlastImporter::getResultObject($chado_connection, $blast_xml, $db_object, $feature_id, $analysis_object);
        $blast_object->analysis = $analysis_object;
        $blast_object->feature_name = $analysisfeatureprop->feature_name;
        $blast_object->feature_uniquename = $analysisfeatureprop->feature_uniquename;
        $blast_object_array[$blast_object_counter] = $blast_object;
        $blast_object_counter++;
      }
    }

    return $blast_object_array;
  }

}
