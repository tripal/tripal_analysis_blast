<?php

namespace Drupal\tripal_analysis_blast\Plugin\Field\FieldType;

use Drupal\core\Field\FieldDefinitionInterface;
use Drupal\core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tripal\TripalField\Attribute\TripalFieldType;
use Drupal\tripal\Entity\TripalEntityType;
use Drupal\tripal_chado\TripalField\ChadoFieldItemBase;
use Drupal\tripal_chado\TripalStorage\ChadoIntStoragePropertyType;
use Drupal\tripal_chado\TripalStorage\ChadoTextStoragePropertyType;
use Drupal\tripal_chado\TripalStorage\ChadoVarCharStoragePropertyType;

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
    $chado = \Drupal::service('tripal_chado.database');
#    $lookup_manager = \Drupal::service('tripal.tripal_entity.lookup');
#    $phylotree = new ChadoPhylotree($chado, $lookup_manager);
#    $json = $phylotree->getTreeNewick($record_id);
$json = '{"item1": "value1", "item2": "value2"}';
    return $json;
  }

}
