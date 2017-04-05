<?php

class tripal__blast_result extends ChadoField {

  // The default lable for this field.
  public static $default_label = 'Blast Result';

  // The default description for this field.
  public static $description = 'The blast_result to which this resource is associated.';

  // Provide a list of instance specific settings. These can be access within
  // the instanceSettingsForm.  When the instanceSettingsForm is submitted
  // then Drupal with automatically change these settings for the instnace.
  // It is recommended to put settings at the instance level whenever possible.
  // If you override this variable in a child class be sure to replicate the
  // term_name, term_vocab, term_accession and term_fixed keys as these are
  // required for all TripalFields.
  public static $default_instance_settings  = array(
    // The short name for the vocabulary (e.g. shcema, SO, GO, PATO, etc.).
    'term_vocabulary' => 'tripal',
    // The name of the term.
    'term_name' => 'blast_result',
    // The unique ID (i.e. accession) of the term.
    'term_accession' => 'blast_result',
    // Set to TRUE if the site admin is allowed to change the term
    // type. This will create form elements when editing the field instance
    // to allow the site admin to change the term settings above.
    'term_fixed' => FALSE,
  );

  // The default widget for this field.
  public static $default_widget = 'tripal__blast_result_widget';

  // The default formatter for this field.
  public static $default_formatter = 'tripal__blast_result_formatter';

  /**
   * @see TripalField::load()
   */
  public function load($entity, $details = array()) {

    $record = $details['record'];
    $settings = $this->instance['settings'];

    $field_name = $this->field['field_name'];
    $field_type = $this->field['type'];
    $field_table = $this->instance['settings']['chado_table'];
    $field_column = $this->instance['settings']['chado_column'];

    // Get the terms for each of the keys for the 'values' property.
    $label_term = 'rdfs:label';
    $genus_term = tripal_get_chado_semweb_term('blast_result', 'genus');
    $species_term = tripal_get_chado_semweb_term('blast_result', 'species');
    $infraspecific_name_term = tripal_get_chado_semweb_term('blast_result', 'infraspecific_name');
    $infraspecific_type_term = tripal_get_chado_semweb_term('blast_result', 'type_id');

    // Set some defaults for the empty record.
    $entity->{$field_name}['und'][0] = array(
      'value' => array(
        /*
        // Types of elements that will appear in the value array.
        $label_term => '',
        $genus_term => '',
        $species_term => '',
        */
      ),
    );

    if ($record) {
      $blast_result = $record->blast_result_id;
      $string = $settings['field_display_string'];
      $label = tripal_replace_chado_tokens($string, $blast_result);
      $entity->{$field_name}['und'][0]['value'] = array(
        $label_term => $label,
        $genus_term => $blast_result->genus,
        $species_term => $blast_result->species,
      );
      // The infraspecific fiels were introdcued in Chado v1.3.
      if (property_exists($blast_result, 'infraspecific_name')) {
        $entity->{$field_name}['und'][0]['value'][$infraspecific_type_term] = NULL;
        $entity->{$field_name}['und'][0]['value'][$infraspecific_name_term] = $blast_result->infraspecific_name;
        if ($blast_result->type_id) {
          $entity->{$field_name}['und'][0]['value'][$infraspecific_type_term] =  $blast_result->type_id->name;
        }
      }
      $entity->{$field_name}['und'][0]['chado-' . $field_table . '__blast_result_id'] = $blast_result->blast_result_id;

      // Is there a published entity for this blast_result?
      if (property_exists($entity->chado_record->$field_column, 'entity_id')) {
        $fk_entity_id = $entity->chado_record->$field_column->entity_id;
        $entity->{$field_name}['und'][0]['value']['entity'] = 'TripalEntity:' . $fk_entity_id;
      }
    }
  }
}
