<?php

/**
 * Implements hook_bundle_create_fields().
 *
 * This is a Tripal defined hook that supports integration with the
 * TripalEntity field.
 */
function tripal_analysis_blast_bundle_create_fields($entity_type, $bundle) {

  $chado_bundle = db_select('chado_bundle', 'cb')
    ->fields('cb')
    ->condition('bundle_id', $bundle->id)
    ->execute()
    ->fetchObject();

  // Get the details about the mapping of this bundle to the Chado table:
  $details = array(
    'chado_cvterm_id' => $chado_bundle->type_id,
    'chado_table' => $chado_bundle->data_table,
    'chado_type_table' => $chado_bundle->type_linker_table,
    'chado_type_column' => $chado_bundle->type_column,
  );

  $info = array();

  // Create the fields for each column in the table.
  tripal_analysis_blast_bundle_create_field($info, $details, $entity_type, $bundle);

  return $info;

}

/**
 *
 * @param unknown $details
 */
function tripal_analysis_blast_bundle_create_field(&$info, $details, $entity_type, $bundle) {
  $table_name = $details['chado_table'];
  $type_table = $details['chado_type_table'];
  $type_field = $details['chado_type_column'];
  $cvterm_id  = $details['chado_cvterm_id'];

  $schema = chado_get_schema($table_name);

  // FEATURE RESIDUES
  if ($table_name == 'feature') {
    $field_name = 'data__sequence';
    $field_type = 'data__sequence';
    $info[$field_name] = array(
      'field_name' => $field_name,
      'type' => $field_type,
      'cardinality' => 1,
      'locked' => FALSE,
      'storage' => array(
        'type' => 'field_chado_storage',
      ),
    );
  }
}

/**
 * Impelments hook_create_tripalfield_instance().
 *
 * This is a Tripal defined hook that supports integration with the
 * TripalEntity field.
 */
function tripal_analysis_blast_bundle_create_instances($entity_type, $bundle) {

  $chado_bundle = db_select('chado_bundle', 'cb')
    ->fields('cb')
    ->condition('bundle_id', $bundle->id)
    ->execute()
    ->fetchObject();

  $details = array(
    'chado_cvterm_id' => $chado_bundle->type_id,
    'chado_table' => $chado_bundle->data_table,
    'chado_type_table' => $chado_bundle->type_linker_table,
    'chado_type_column' => $chado_bundle->type_column,
  );

  $info = array();
  tripal_analysis_blast_bundle_create_instance($info, $entity_type, $bundle, $details);

  return $info;

}

/**
 * Helper function for the hook_create_tripalfield_instance().
 *
 * Adds custom fields for base fields.  These override the settings provided
 * in the tripal_analysis_blast_create_tripalfield_instance_base() function.
 *
 * @param $entity_type
 * @param $bundle
 * @param $details
 */
function tripal_analysis_blast_bundle_create_instance(&$info, $entity_type, $bundle, $details) {
  $table_name = $details['chado_table'];
  $type_table = $details['chado_type_table'];
  $type_field = $details['chado_type_column'];
  $cvterm_id  = $details['chado_cvterm_id'];
  $schema = chado_get_schema($table_name);

  // FEATURE MD5CHECKSUM
  if ($table_name == 'feature') {
    $field_name = 'data__sequence_checksum';
    $info[$field_name] = array(
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'bundle' => $bundle->name,
      'label' => 'Sequence Checksum',
      'description' => 'The MD5 checksum for the sequence. The checksum here
        will always be unique for the raw unformatted sequence. To verify that the
        sequence has not been corrupted, download the raw sequence and use an MD5 tool
        to calculate the value. If the value calculated is identical the one shown
        here, then the downloaded sequence is uncorrupted.',
      'required' => FALSE,
      'settings' => array(
        'auto_attach' => TRUE,
        'chado_table' => $table_name,
        'chado_column' => 'md5checksum',
        'base_table' => $table_name,
      ),
      'widget' => array(
        'type' => 'data__sequence_checksum_widget',
        'settings' => array(
          'display_label' => 1,
          'md5_fieldname' => 'feature__md5checksum',
        ),
      ),
      'display' => array(
        'default' => array(
          'label' => 'inline',
          'type' => 'data__sequence_checksum_formatter',
          'settings' => array(),
        ),
      ),
    );
  }
}
