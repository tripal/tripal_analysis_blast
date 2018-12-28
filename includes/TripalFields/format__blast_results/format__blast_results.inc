<?php

class format__blast_results extends ChadoField {

  // The default lable for this field.
  public static $default_label = 'BLAST Results';

  // The default description for this field.
  public static $description = 'Results of a sequence database search using BLAST.';

  // Provide a list of instance specific settings. These can be access within
  // the instanceSettingsForm.  When the instanceSettingsForm is submitted
  // then Drupal with automatically change these settings for the instnace.
  // It is recommended to put settings at the instance level whenever possible.
  // If you override this variable in a child class be sure to replicate the
  // term_name, term_vocab, term_accession and term_fixed keys as these are
  // required for all TripalFields.
  public static $default_instance_settings  = array(
    // The short name for the vocabulary (e.g. shcema, SO, GO, PATO, etc.).
    'term_vocabulary' => 'format',
    // The name of the term.
    'term_name' => 'BLAST results',
    // The unique ID (i.e. accession) of the term.
    'term_accession' => '1333',
    // Set to TRUE if the site admin is allowed to change the term
    // type. This will create form elements when editing the field instance
    // to allow the site admin to change the term settings above.
    'term_fixed' => FALSE,
  );

  // The default widget for this field.
  public static $default_widget = 'format__blast_results_widget';

  // The default formatter for this field.
  public static $default_formatter = 'format__blast_results_formatter';

  /**
   * @see TripalField::load()
   */
  public function load($entity, $details = array()) {
    $record = $entity->chado_record;

    $settings = $this->instance['settings'];
    $field_name = $this->field['field_name'];
    $field_type = $this->field['type'];
    $field_table = $this->instance['settings']['chado_table'];
    $field_column = $this->instance['settings']['chado_column'];

    // Get the terms for each of the keys for the 'values' property.
    $label_term = 'format:1333';

    // Set some defaults for the empty record.
    $entity->{$field_name}['und'][0] = array(
      'value' => ''
    );

    // Get the list of results.
    $blast_results = tripal_get_feature_blast_results($record->feature_id, NULL);
    if (is_array($blast_results)) {
      foreach ($blast_results as $delta => $results) {
        $xml = $results->xml;
        unset($results->xml);
        $entity->{$field_name}['und'][$delta] = array(
          'value' => $xml,
          'parsed' => $results
        );
      }
    }
  }
}
