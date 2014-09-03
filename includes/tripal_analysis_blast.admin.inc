<?php
/**
 * 
 */
function tripal_analysis_blast_settings_form($form, &$form_state) {

  // Default values can come in the following ways:
  //
  // 1) when the form is first loaded, everything is default to empty value 
  // 2) on an ajax call, the blastdb value will be different from the db_id value indicating 
  //     the user has changed the database. Therefore, we need to pull default from the database
  // 3) on a validation error, the db_id and blastdb are the same, we need to populate the
  //     defaults using form_state values
  //
  // case #1 set form field defaults on first load
  $displayname = NULL;
  $gb_style_parser = 0;
  $hit_id = NULL;
  $hit_def = NULL;
  $hit_accession = NULL;
  $hit_organism_re = NULL;
  $hit_organism = NULL;
  $read_only = 0;
  $blastdb = 0;
  $db_id = NULL;
  
  $form['db_settings'] = array(
    '#type' => 'fieldset',
    '#title' => 'BLAST Parsing',
    '#collapsible' => TRUE,
    '#collapsed' => FALSE,
    '#description' => t('You may customize how blast results are parsed and 
        displayed to the end-user.  Select a database from the list to 
        show the customizable fields.  The fields specify how to extract hit 
        names, descriptions, database accessions, and organisms from the blast 
        results.')
  );
  
  // if there is form_state values, there has been an ajax callback or validation error,
  // we need to distinguish which one
  if ($form_state && key_exists('values', $form_state)) {
    $blastdb = $form_state['values']['blastdb'];
    $db_id = $form_state['values']['db_id'];
    
    // Find out the triggering element
    $triggered_by = $form_state['input']['_triggering_element_name'];

    // case #2 set default after ajax callback
    if ($db_id != $blastdb) {
      $sql = "SELECT * FROM {tripal_analysis_blast} WHERE db_id = :db_id";
      $results = db_query($sql,  array(':db_id' => $blastdb));
      $db = $results->fetchObject();
      $db_id = $db->db_id;
      $displayname = $db->displayname;
      
      // If it was triggered by selecting the database
      if ($triggered_by == 'blastdb') {
        $gb_style_parser = $db->genbank_style;
      
      // or it was triggered by clicking on the Genbank style checkbox
      }
      else {
        $gb_style_parser = $form_state['values']['gb_style_parser'];
        if ($gb_style_parser) {
          unset($form_state['values']['hit_id']);
          unset($form_state['values']['hit_def']);
          unset($form_state['values']['hit_accession']);
          unset($form_state['values']['hit_organism_re']);
          unset($form_state['values']['hit_organism']);
        }
      }
      $hit_id = $db->regex_hit_id;
      $hit_def = $db->regex_hit_def;
      $hit_accession = $db->regex_hit_accession;
      $hit_organism_re = $db->regex_hit_organism;
      $hit_organism = $db->hit_organism;
      if ($gb_style_parser) {
        $read_only = 1;
      }
      // default values won't change if there are elements in the $form_state['input']
      unset($form_state['input']['displayname']);
      unset($form_state['input']['gb_style_parser']);
      unset($form_state['input']['hit_id']);
      unset($form_state['input']['hit_def']);
      unset($form_state['input']['hit_accession']);
      unset($form_state['input']['hit_organism_re']);
      unset($form_state['input']['hit_organism']);
    }
    
    // case #3 set default after validation error
    else {
      $displayname = $form_state['values']['displayname'];
      $gb_style_parser = $form_state['values']['gb_style_parser'];
      if ($triggered_by == 'gb_style_parser' && $gb_style_parser) {
        unset($form_state['values']['hit_id']);
        unset($form_state['values']['hit_def']);
        unset($form_state['values']['hit_accession']);
        unset($form_state['values']['hit_organism_re']);
        unset($form_state['values']['hit_organism']);
        unset($form_state['input']['hit_id']);
        unset($form_state['input']['hit_def']);
        unset($form_state['input']['hit_accession']);
        unset($form_state['input']['hit_organism_re']);
        unset($form_state['input']['hit_organism']);
        $read_only = 1;
      }

      $hit_id = $form_state['values']['hit_id'];
      $hit_def = $form_state['values']['hit_def'];
      $hit_accession = $form_state['values']['hit_accession'];
      $hit_organism_re = $form_state['values']['hit_organism_re'];
      $hit_organism = $form_state['values']['hit_organism'];
    }
  }
  
  $form['db_settings']['db_id'] = array(
    '#type' => 'value',
    '#value' => $db_id,
  );
  
  // get a list of db from chado for user to choose
  $sql = 'SELECT db_id,  name FROM {db} ORDER BY lower(name)';
  $results = chado_query($sql);
  
  $blastdbs = array();
  $blastdbs[] = 'Select a Database';
  while ($db = $results->fetchObject()) {
    $blastdbs[$db->db_id] = $db->name;
  }
  $form['db_settings']['db_options'] = array(
      '#type' => 'value',  
      '#value' => $blastdbs
  );
  $form['db_settings']['blastdb'] = array(
    '#title' => t('Database'),  
    '#type' => 'select',  
    '#options' => $form['db_settings']['db_options']['#value'],
    '#default_value' => $blastdb,  
    '#ajax' => array(
      'callback'    => 'tripal_analysis_blast_settings_form_ajax',
      'wrapper' => 'tripal-analysis-blast-settings-form-wrapper',
      'effect'   => 'fade',
      'event'   => 'change',
      'method'  => 'replace',
    ),    
  );
  if ($blastdb != 0) {
  $form['db_settings']['displayname'] = array(
      '#title' => t('Title for the blast analysis'),  
      '#type' => 'textfield', 
      '#value' => $displayname, 
  );
  $form['db_settings']['gb_style_parser'] = array(
      '#title' => t('Use Genbank style parser. This will clear all regular expression settings for the selected database.'),  
      '#type' => 'checkbox', 
      '#default_value' => $gb_style_parser, 
      '#ajax' => array(
          'callback'    => 'tripal_analysis_blast_settings_form_ajax',
          'wrapper' => 'tripal-analysis-blast-settings-form-wrapper',
          'effect'   => 'fade',
          'event'   => 'change',
          'method'  => 'replace',
      ),
  );
  $form['db_settings']['hit_id'] = array(
      '#title' => t('Regular expression for Hit Name'),  
      '#type' => 'textfield',  
      '#default_value' => $hit_id,
      '#disabled' => $read_only,
  );
  $form['db_settings']['hit_def'] = array(
      '#title' => t('Regular expression for Hit Description'),  
      '#type' => 'textfield',  
      '#default_value' => $hit_def,
      '#disabled' => $read_only,
  );
  $form['db_settings']['hit_accession'] = array(
      '#title' => t('Regular expression for Hit Accession'),  
      '#type' => 'textfield',
      '#default_value' => $hit_accession,  
      '#disabled' => $read_only,
  );
  $form['db_settings']['hit_organism_re'] = array(
      '#title' => t('Regular expression for Organism.'),  
      '#type' => 'textfield',  
      '#default_value' => $hit_organism_re,
      '#disabled' => $read_only,
  );
  $form['db_settings']['hit_organism'] = array(
      '#title' => t('Organism Name.'),  
      '#description' => t('If the organism for the match cannot be determined using
          a regular expression but the database only contains a single organism,  then
          you may specify the organism here. Be sure to include the genus,  species,
          subspeices,  cultivar, etc.,  to properly identify the organism. If the organism
          cannot be determined using a regular expression or is not unique in the database
          then leave this field blank.'),        
      '#type' => 'textfield',  
      '#default_value' => $hit_organism,
      '#disabled' => $read_only,
  );
  $form['db_settings']['button'] = array(
      '#type' => 'submit',  
      '#value' => t('Save settings')
  );
  }
  $form['db_settings']['#prefix'] = '<div id="tripal-analysis-blast-settings-form-wrapper">';
  $form['db_settings']['#suffix'] = '</div>';
  

  // Add fields for setting the node titles
  $details = array(
    'module' => 'tripal_analysis_blast',       // the name of the MODULE implementing the content type
    'content_type' => 'chado_analysis_blast',   // the name of the content type
    'options' => array(
      '[analysis.name]' => 'Analysis Name Only',
      '[analysis.name] ([analysis.sourcename]) [analysis.program] version [analysis.programversion]' => 'Unique Contraint: Includes the name, source and program name/version'
    ),
    'unique_option' => '[analysis.name] ([analysis.sourcename]) [analysis.program] version [analysis.programversion]'
  );
  chado_add_admin_form_set_title($form, $form_state, $details);
  
  // Add form elements for customizing the node URLs.
  $details = array(
    'module' => 'tripal_analysis_blast',
    'content_type' => 'chado_analysis_blast',
    // An array of options to use under "Page URL"
    // the key should be the token and the value should be the human-readable option
    'options' => array(
      '/analysis/[analysis.analysis_id]' => 'Analysis ID',
      '/analysis/[analysis.program]/[analysis.programversion]/[analysis.sourcename]' => 'Unique Contraint: Includes the program name & version as well as the source name'
    )
  );
  chado_add_admin_form_set_url($form, $form_state, $details);
  
  return $form;
}
/**
 * 
 */
function tripal_analysis_blast_settings_form_submit($form,  &$form_state) {
  $db_id = key_exists('blastdb', $form_state['input']) ? trim($form_state['input']['blastdb']) : '';
  $name = key_exists('displayname', $form_state['input']) ? trim($form_state['input']['displayname']) : '';
  $gbstyle = key_exists('gb_style_parser', $form_state['input']) ? trim($form_state['input']['gb_style_parser']) : '';
  $reg1 = key_exists('hit_id', $form_state['input']) ? trim($form_state['input']['hit_id']) : '';
  $reg2 = key_exists('hit_def', $form_state['input']) ? trim($form_state['input']['hit_def']) : '';
  $reg3 = key_exists('hit_accession', $form_state['input']) ? trim($form_state['input']['hit_accession']) : '';
  $reg4 = key_exists('hit_organism_re', $form_state['input']) ? trim($form_state['input']['hit_organism_re']) : '';
  $org = key_exists('hit_organism', $form_state['input']) ? trim($form_state['input']['hit_organism']) : '';

  // Check if the blast settings exists
  $sql = "SELECT db_id FROM {tripal_analysis_blast} WHERE db_id = :db_id";
  $result = db_query($sql,  array(':db_id' => $db_id));
  $check = $result->rowCount();
  
  $record = new stdClass();
  $record->db_id = $db_id;
  $record->displayname = $name;
  $record->regex_hit_id = $reg1;
  $record->regex_hit_def = $reg2;
  $record->regex_hit_accession = $reg3;
  $record->genbank_style = $gbstyle;
  $record->regex_hit_organism = $reg4;
  $record->hit_organism = $org;
  
  if ($check) {
    $success = drupal_write_record('tripal_analysis_blast',  $record, 'db_id');
  } 
  else {
    $success = drupal_write_record('tripal_analysis_blast',  $record);
  }
  if ($success) {
     drupal_set_message("Database settings updated");
  } 
  else {
     drupal_set_message("Failure updated database settings", 'error');
  }
}
/**
 * 
 */
function tripal_analysis_blast_settings_form_ajax($form, $form_state) {
  return $form;
}