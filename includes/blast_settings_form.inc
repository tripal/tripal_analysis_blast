<?php
/**
 * 
 */
function tripal_analysis_blast_settings_form(&$form_state = NULL) {
	tripal_core_ahah_init_form();
	
	// get the db if already selected via an AHAH callback
	$db_id = $form_state['values']['blastdb'];
	
	
  // Get an array of node types with internal names as keys
  $options = node_get_types('names');
  
  // Add 'chado_feature' to allowed content types for showing blast results
  $allowedoptions ['chado_feature'] = "Show blast results on feature pages";

  $form['tripal_analysis_blast_setting'] = array(
      '#type' => 'checkboxes',  
      '#options' => $allowedoptions,
      '#default_value' => variable_get('tripal_analysis_blast_setting',  array('chado_feature')),  
  );

  
  // get a list of db from chado for user to choose
  $sql = 'SELECT db_id,  name FROM {db} ORDER BY lower(name)';
  $results = chado_query($sql);
  
  $blastdbs = array();
  $blastdbs[] = 'Select a Database';
  while ($db = db_fetch_object($results)) {
    $blastdbs[$db->db_id] = $db->name;
  }
  $form['db_options'] = array(
      '#type' => 'value',  
      '#value' => $blastdbs
  );
  $form['blastdb'] = array(
    '#title' => t('Database'),  
    '#type' => 'select',  
    '#description' => t('You may customize how blast results are parsed and displayed to the end-user,  by selecting a database from the list above, and the customizing the fields below.  The fields below specify how to extract hit names, descriptions, database accessions, and organisms from the blast results.'), 
    '#options' => $form['db_options']['#value'],
    '#default_value' => $db_id,  
    '#ahah' => array(
      'path'    => 'admin/tripal/tripal_analysis/tripal_blast_analysis/update_settings',
      'wrapper' => 'tripal-analysis-blast-settings-form',
      'event'   => 'change',
      'method'  => 'replace',
    ),    
  );
  
  // now get the blast parameters if a given db_id is provided
  if ($db_id) {  	
    $sql = "SELECT * FROM {tripal_analysis_blast} WHERE db_id = %d";
    $db = db_fetch_object(db_query($sql,  $db_id));
    
    
    $displayname = $db->displayname;
    $gb_style_parser = $db->genbank_style;
    $hit_id = $db->regex_hit_id;
    $hit_def = $db->regex_hit_def;
    $hit_accession = $db->regex_hit_accession;
    $hit_organism_re = $db->regex_hit_organism;
    $hit_organism = $db->hit_organism;
    $read_only = 0;
    if ($gb_style_parser) {
    	$read_only = 1;
    }
  }
  
  $form['displayname'] = array(
      '#title' => t('Title for the blast analysis'),  
      '#type' => 'textfield', 
      '#default_value' => $displayname, 
  );
  $form['gb_style_parser'] = array(
      '#title' => t('Use Genebank style parser. This will clear all regular expression settings for the selected database.'),  
      '#type' => 'checkbox', 
      '#default_value' => $gb_style_parser, 
      '#attributes' => array(
         'onClick' => "return tripal_set_genbank_style(this)",  
      ),      
  );
  $form['hit_id'] = array(
      '#title' => t('Regular expression for Hit Name'),  
      '#type' => 'textfield',  
      '#default_value' => $hit_id,
      '#disabled' => $read_only,
  );
  $form['hit_def'] = array(
      '#title' => t('Regular expression for Hit Description'),  
      '#type' => 'textfield',  
      '#default_value' => $hit_def,
      '#disabled' => $read_only,
  );
  $form['hit_accession'] = array(
      '#title' => t('Regular expression for Hit Accession'),  
      '#type' => 'textfield',
      '#default_value' => $hit_accession,  
      '#disabled' => $read_only,
  );
  $form['hit_organism_re'] = array(
      '#title' => t('Regular expression for Organism.'),  
      '#type' => 'textfield',  
      '#default_value' => $hit_organism_re,
      '#disabled' => $read_only,
  );
  $form['hit_organism'] = array(
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
  $form['button'] = array(
      '#type' => 'submit',  
      '#value' => t('Save settings')
  );

  
  return $form;
}
/**
 * 
 */
function tripal_analysis_blast_settings_form_submit($form,  &$form_state) {
  $db_id = $form_state['values']['blastdb'];
  $name = $form_state['values']['displayname'];
  $gbstyle = $form_state['values']['gb_style_parser'];
  $reg1 = $form_state['values']['hit_id'];
  $reg2 = $form_state['values']['hit_def'];
  $reg3 = $form_state['values']['hit_accession'];
  $reg4 = $form_state['values']['hit_organism_re'];
  $org = $form_state['values']['hit_organism'];
  
  // Check if the blast settings exists
  $sql = "SELECT db_id FROM {tripal_analysis_blast} WHERE db_id=%d";
  $check = db_result(db_query($sql,  $db_id));
  
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
     drupal_set_message("Failure updated database settings");
  }
}
/**
 * 
 */
function tripal_analysis_blast_settings_form_callback() {
  $status = TRUE;

  // prepare and render the form
  $form = tripal_core_ahah_prepare_form();   
  $data = drupal_render($form);  

  // bind javascript events to the new objects that will be returned 
  // so that AHAH enabled elements will work.
  $settings = tripal_core_ahah_bind_events();

  // return the updated JSON
  drupal_json(
    array(
      'status'   => $status, 
      'data'     => $data,
      'settings' => $settings,
    )  
  );
}
