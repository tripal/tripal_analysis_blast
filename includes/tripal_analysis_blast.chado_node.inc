<?php


/*******************************************************************************
 *  Provide information to drupal about the node types that we're creating
*  in this module
*/
function tripal_analysis_blast_node_info() {
  $nodes = array();
  $nodes['chado_analysis_blast'] = array(
      'name' => t('Analysis: Blast'),
      'base' => 'chado_analysis_blast',
      'description' => t('A blast analysis from the chado database'),
      'has_title' => FALSE,
      'title_label' => t('Analysis: Blast'),
      'has_body' => FALSE,
      'body_label' => t('Blast Analysis Description'),
      'locked' => TRUE
  );
  return $nodes;
}


/*******************************************************************************
 * tripal_analysis_blast_nodeapi()
* HOOK: Implementation of hook_nodeapi()
* Display blast results for allowed node types
*/
function tripal_analysis_blast_node_view($node, $view_mode, $langcode) {

  switch ($node->type) {
    case 'chado_analysis_blast':
      if ($view_mode == 'full') {
        $node->content['tripal_analysis_blast_base'] = array(
          '#markup' => theme('tripal_analysis_blast_base', array('node' => $node)),
          '#tripal_toc_id'    => 'base',
          '#tripal_toc_title' => 'Overview',
          '#weight' => -100,
        );
      }
      if ($view_mode == 'teaser') {
        $node->content['tripal_analysis_blast_teaser'] = array(
          '#markup' => theme('tripal_analysis_blast_teaser', array('node' => $node)),
        );
      }
      break;
    case 'chado_feature':
      if ($view_mode == 'full') {
        $node->content['tripal_feature_blast_results'] = array(
          '#markup' => theme('tripal_feature_blast_results', array('node' => $node)),
          '#tripal_toc_id'    => 'homology',
          '#tripal_toc_title' => 'Homology',
        );
      }
      break;
  }
}

/*******************************************************************************
 *  Provide a Blast Analysis form
 */
function chado_analysis_blast_form($node, $form_state) {

  // add in the default fields
  $form = chado_analysis_form($node, $form_state);
  
  // Default values can come in the following ways:
  //
  // 1) as elements of the $node object.  This occurs when editing an existing library
  // 2) in the $form_state['values'] array which occurs on a failed validation or
  //    ajax callbacks from non submit form elements
  // 3) in the $form_state['input'] array which occurs on ajax callbacks from submit
  //    form elements and the form is being rebuilt
  //
  // set form field defaults
  $blast = NULL;
  $blastdb = '';
  $blastfile = '';
  $blastfile_ext = '';
  $blastparameters = '';
  $query_re = '';
  $query_type = '';
  $query_uniquename = '';
  $is_concat = '';
  $search_keywords = '';
  $blastjob = FALSE;
  
  // if we are editing an existing node then the library is already part of the node
  if (property_exists($node, 'analysis')) {
    $blast = $node->analysis->tripal_analysis_blast;
    $blastdb          = $blast->blastdb;
    $blastfile        = $blast->blastfile;
    $blastfile_ext    = $blast->blastfile_ext;
    $blastparameters  = $blast->blastparameters;
    $query_re         = $blast->query_re;
    $query_type       = $blast->query_type;
    $query_uniquename = $blast->query_uniquename;
    $is_concat        = $blast->is_concat;
    $search_keywords  = $blast->search_keywords;
  }
  // if we are re constructing the form from a failed validation or ajax callback
  // then use the $form_state['values'] values
  if (array_key_exists('values', $form_state)) {
    $blastdb          = $form_state['values']['blastdb'];
    $blastfile        = $form_state['values']['blastfile'];
    $blastfile_ext    = $form_state['values']['blastfile_ext'];
    $blastparameters  = $form_state['values']['blastparameters'];
    $query_re         = $form_state['values']['query_re'];
    $query_type       = $form_state['values']['query_type'];
    $query_uniquename = $form_state['values']['query_uniquename'];
    $is_concat        = $form_state['values']['is_concat'];
    $search_keywords  = $form_state['values']['search_keywords'];
  }
  // if we are re building the form from after submission (from ajax call) then
  // the values are in the $form_state['input'] array
  if (array_key_exists('input', $form_state) and !empty($form_state['input'])) {
    $blastdb          = $form_state['input']['blastdb'];
    $blastfile        = $form_state['input']['blastfile'];
    $blastfile_ext    = $form_state['input']['blastfile_ext'];
    $blastparameters  = $form_state['input']['blastparameters'];
    $query_re         = $form_state['input']['query_re'];
    $query_type       = $form_state['input']['query_type'];
    $query_uniquename = $form_state['input']['query_uniquename'];
    $is_concat        = $form_state['input']['is_concat'];
    $search_keywords  = $form_state['input']['search_keywords'];
  }
    
  $form['blast'] = array(
    '#title' => t('Blast Settings'),  
    '#type' => 'fieldset',  
    '#description' => t('Specific Settings for Blast Analysis.'),  
    '#collapsible' => TRUE,
    '#attributes' => array('id' => 'blast-extra-settings'),  
    '#weight' => 11
  );
  
  // get a list of db from chado for user to choose
  $sql = 'SELECT db_id,  name FROM {db} ORDER BY lower(name)';
  $results = chado_query($sql);
  
  $blastdbs = array();
  while ($db = $results->fetchObject()) {
    $blastdbs[$db->db_id] = $db->name;
  }
  $form['db_options'] = array(
      '#type' => 'value',  
      '#value' => $blastdbs
  );
  $form['blast']['blastdb'] = array(
      '#title' => t('Database'),  
      '#type' => 'select',  
      '#description' => t('The database used for the blast analysis. If the database does not appear in this list, please ') . 
        l(t('add a new database.  '), 'admin/tripal/tripal_db/add_db', array('attributes' => array('target' => '_blank')) ) . 
        t('Each database may have a different format for each match. This blast module will attempt to extract the match name,
         match accession, and organism from each match.  To ensure the parser is able to properly extract
         this information. Please set the proper regular expression values on the ') . 
         l(t('Blast Settings page. '), 'admin/tripal/tripal_analysis/tripal_blast_analysis',array('attributes' => array('target' => '_blank'))  ) .
         t('Databases from NCBI have a built-in parser. On the Blast Settings page, simply click the box "Use Genebank style parser"'), 
      '#options' => $form['db_options']['#value'],  
      '#default_value' => $blastdb,
  );

  $form['blast']['blastfile'] = array(
      '#title' => t('Blast XML File/Directory: (if you input a directory without the tailing slash,  all xml files in the directory will be loaded)'), 
      '#type' => 'textfield',  
      '#description' => t('The xml output file generated by blast in full path.'),  
      '#default_value' => $blastfile,
  ); 
   $form['blast']['blastfile_ext'] = array(
      '#title' => t('Blast XML file extension'),  
      '#type' => 'textfield',  
      '#description' => t('If a directory is provide for the blast file setting above,  then a file extension can be provided here. Files with this extension in the directory will be parsed.  If no extension is provided then files with a .xml extension will be parsed within the directory. Please provide the extension without the preceeding period (e.g. "out" rather than ".out"'), 
      '#default_value' => $blastfile_ext,
  );
  $form['blast']['is_concat'] = array(
      '#title' => t('Is the XML file concatenated?'),  
      '#type' => 'checkbox',  
      '#description' => t('Is the XML file a set of concatenated XML results?  Such is the case,  for instance, if <a href="http://www.blast2go.org/">Blast2GO</a> was used to generate the blast results. If
      NCBI BLAST was used with output in XML then this options should not be checked.'),  
      '#default_value' => $is_concat,
  );
  $form['blast']['no_parsed'] = array(
    '#title' => t('Number of hits to be parsed'),  
      '#type' => 'textfield',  
      '#description' => t("The number of hits to be parsed. Tripal will parse only top 10 hits if you input '10'' in this field. Enter the text 'all' to parse all hits. Default is to parse only the top 25 hits per match."),  
      '#default_value' => '25',  
  );

  $form['blast']['query_re'] = array(
      '#title' => t('Query Name RE'),  
      '#type' => 'textfield',  
      '#description' => t('Enter the regular expression that will extract the '.
         'feature name from the query line in the blast results. This should be '.
         'the same as the definition line in the query FASTA file.  This option is '.
         'is only required when the query does not identically match a feature '.
         'in the database.'),  
      '#default_value' => $query_re,
  );

  $form['blast']['query_type'] = array(
      '#title' => t('Query Type'),  
      '#type' => 'textfield',  
      '#description' => t('Please enter the Sequence Ontology term that describes '.
         'the query sequences used for blasting.  This is only necessary if two '.
         'or more sequences have the same name.'),  
      '#default_value' => $query_type,
  );

  $form['blast']['query_uniquename'] = array(
      '#title' => t('Use Unique Name'),  
      '#type' => 'checkbox',  
      '#description' => t('Select this checboxk if the query name in the blast file '.
        'matches the uniquename of the feature.  By default,  the blast results will '.
        'mapped to the "name" of the feature.'),  
      '#default_value' => $query_uniquename,
  );

  $form['blast']['blastparameters'] = array(
      '#title' => t('Parameters'),  
      '#type' => 'textfield',  
      '#description' => t('The parameters for running the blast analysis.'),  
      '#default_value' => $blastparameters,
  );
  
  $form['blast']['blastjob'] = array(
      '#type' => 'checkbox',  
      '#title' => t('Submit a job to parse the xml output into Chado'),  
      '#description' => t('Note: features associated with the blast results must '.
                          'exist in chado before parsing the file. Otherwise,  blast '.
                          'results that cannot be linked to a feature will be '.
                          'discarded. '),  
      '#default_value' => $blastjob
  );
   
  $search_keywords_options = array(
     'blast_match_name' => '*The match name (property name: blast_match_name)',  
     'blast_match_description' => '*The match description (property name: blast_match_description)',       
     'blast_match_organism' => '*The organism the match belongs to. (property name: blast_match_organism)',  
      'blast_match_accession' => '*The accession of the match. (property name: blast_match_accession)',  
     'blast_database' => 'The name of the database blasted against (property name: blast_database)',       
//     'blast_evalue' => 'The match e-value score (property name: blast_evalue)',  
//     'blast_score' => 'The match bit score (property name: blast_score)',  
//     'blast_pid' => 'The match percent identity (property name: blast_pid)',       
  );
  $form['blast']['search_keywords'] = array(
      '#type' => 'checkbox',  
      '#description' => t('By default the blast results will be available for Drupal-style 
         search indexing. However,  custom searching can be made available to site visitors using 
         the Tripal Views module.  Some fields can only be identified in the blast results if the 
         database record in Tripal is properly configured. Navigate to Administer -> 
         Tripal Management -> Analysis and look for the \'Tripal Blast\' section. Select 
         the database that these blast results belong to. Be sure
         to set the regular expressions for identifying the match name,  accession, description and 
         organism.  When constructing a materialized view for these data,  or for setting a 
         search filter in Drupal Views,  use the property names indicated in parentheses above. These
         results will be housed in the analysisfeatureprop table of Chado.'),  
      '#title' => t('Keywords For Custom Search'),  
      '#default_value' => $search_keywords,
      '#description' => t('Parse blast results for integration with Tripal Views'),  
  );
  
  return $form;
}
/**
*
*/
function chado_analysis_blast_validate($node,  &$form, &$form_state) {
   // use the analysis parent to validate the node
   tripal_analysis_validate($node,  $form, $form_state); 
}

function chado_analysis_blast_load($nodes) {

  // load the default set of analysis fields
  chado_analysis_load($nodes);
  
  foreach ($nodes as $nid => $node) {

    // create some variables for easier lookup
    $analysis = $node->analysis;
    $analysis_id = $analysis->analysis_id;
    
    $blast_settings  = tripal_analysis_get_property($analysis->analysis_id, 'analysis_blast_settings');
    $blastdb         = tripal_analysis_get_property($analysis->analysis_id, 'analysis_blast_blastdb');
    $blastfile       = tripal_analysis_get_property($analysis->analysis_id, 'analysis_blast_blastfile');
    $blastparameters = tripal_analysis_get_property($analysis->analysis_id, 'analysis_blast_blastparameters');
    $no_parsed       = tripal_analysis_get_property($analysis->analysis_id, 'analysis_blast_no_parsed');
    $query_re        = tripal_analysis_get_property($analysis->analysis_id, 'analysis_blast_query_re');
    $query_type      = tripal_analysis_get_property($analysis->analysis_id, 'analysis_blast_query_type');
    $query_uniquename= tripal_analysis_get_property($analysis->analysis_id, 'analysis_blast_query_uniquename');
    $blastfile_ext   = tripal_analysis_get_property($analysis->analysis_id, 'analysis_blast_blastfile_ext');
    $is_concat       = tripal_analysis_get_property($analysis->analysis_id, 'analysis_blast_is_concat');
    $search_keywords = tripal_analysis_get_property($analysis->analysis_id, 'analysis_blast_search_keywords');
   
    $analysis->tripal_analysis_blast = new stdClass;
    $analysis->tripal_analysis_blast->blastdb         = is_object($blastdb)          ? $blastdb->value          : '';
    $analysis->tripal_analysis_blast->blastfile       = is_object($blastfile)        ? $blastfile->value        : '';
    $analysis->tripal_analysis_blast->blastparameters = is_object($blastparameters)  ? $blastparameters->value  : '';
    $analysis->tripal_analysis_blast->no_parsed       = is_object($no_parsed)        ? $no_parsed->value        : '';
    $analysis->tripal_analysis_blast->query_re        = is_object($query_re)         ? $query_re->value         : '';
    $analysis->tripal_analysis_blast->query_type      = is_object($query_type)       ? $query_type->value       : '';
    $analysis->tripal_analysis_blast->query_uniquename= is_object($query_uniquename) ? $query_uniquename->value : '';
    $analysis->tripal_analysis_blast->blastfile_ext   = is_object($blastfile_ext)    ? $blastfile_ext->value    : '';
    $analysis->tripal_analysis_blast->is_concat       = is_object($is_concat)        ? $is_concat->value        : '';
    $analysis->tripal_analysis_blast->search_keywords = is_object($search_keywords)  ? $search_keywords->value  : '';

    // get the database information so that we don't have to require callers
    // to do the lookup
    $select = array('db_id' => $blastdb->value);
    $analysis->tripal_analysis_blast->db = tripal_core_generate_chado_var('db',  $select);

    // if there is an old style 'blast_settings' array,  then break these out for
    // use in the new format
    if (count($blast_settings) > 0) {
      $prop_values = explode("|", $blast_settings->value);
      $analysis->tripal_analysis_blast->blastdb = $prop_values[0];
      $analysis->tripal_analysis_blast->blastfile = $prop_values[1];
      $analysis->tripal_analysis_blast->blastparameters = $prop_values[2];
    }
    $nodes[$nid]->analysis = $analysis;
  }
}
/**
 *
 */
function chado_analysis_blast_insert($node) {

  // insert the analysis using the hook_insert() from the Tripal analysis module
  chado_analysis_insert($node);
  
  // now add in the remaining settings as a single property but separated by bars
  tripal_analysis_insert_property($node->analysis_id, 'analysis_blast_blastdb',          trim($node->blastdb));
  tripal_analysis_insert_property($node->analysis_id, 'analysis_blast_blastfile',        trim($node->blastfile));
  tripal_analysis_insert_property($node->analysis_id, 'analysis_blast_blastparameters',  trim($node->blastparameters));
  tripal_analysis_insert_property($node->analysis_id, 'analysis_blast_no_parsed',        trim($node->no_parsed));
  tripal_analysis_insert_property($node->analysis_id, 'analysis_blast_query_re',         trim($node->query_re));
  tripal_analysis_insert_property($node->analysis_id, 'analysis_blast_query_type',       trim($node->query_type));
  tripal_analysis_insert_property($node->analysis_id, 'analysis_blast_query_uniquename', trim($node->query_uniquename));
  tripal_analysis_insert_property($node->analysis_id, 'analysis_blast_blastfile_ext',    trim($node->blastfile_ext));
  tripal_analysis_insert_property($node->analysis_id, 'analysis_blast_is_concat',        trim($node->is_concat));
  tripal_analysis_insert_property($node->analysis_id, 'analysis_blast_search_keywords',  trim($node->search_keywords));

  // submit the parsing jobs
  chado_analysis_blast_submit_jobs($node);
}

/**
  *
  */
function chado_analysis_blast_update($node) {

  // update the anlaysis
  chado_analysis_update($node);

  // add the blast settings
  tripal_analysis_update_property($node->analysis_id, 'analysis_blast_blastdb',          trim($node->blastdb), 1);
  tripal_analysis_update_property($node->analysis_id, 'analysis_blast_blastfile',        trim($node->blastfile), 1);
  tripal_analysis_update_property($node->analysis_id, 'analysis_blast_blastparameters',  trim($node->blastparameters), 1);
  tripal_analysis_update_property($node->analysis_id, 'analysis_blast_no_parsed',        trim($node->no_parsed), 1);
  tripal_analysis_update_property($node->analysis_id, 'analysis_blast_query_re',         trim($node->query_re), 1);
  tripal_analysis_update_property($node->analysis_id, 'analysis_blast_query_type',       trim($node->query_type), 1);
  tripal_analysis_update_property($node->analysis_id, 'analysis_blast_query_uniquename', trim($node->query_uniquename), 1);
  tripal_analysis_update_property($node->analysis_id, 'analysis_blast_blastfile_ext',    trim($node->blastfile_ext), 1);
  tripal_analysis_update_property($node->analysis_id, 'analysis_blast_is_concat',        trim($node->is_concat), 1);
  tripal_analysis_update_property($node->analysis_id, 'analysis_blast_search_keywords',  trim($node->search_keywords), 1);

  // if this analysis uses the old style blast settings cvterm then remove that term
  $old = tripal_analysis_get_property($node->analysis_id, 'analysis_blast_settings');
  if (count($old) > 0) {
    tripal_analysis_delete_property($node->analysis_id, 'analysis_blast_settings');
  }

  // submit the parsing jobs
  chado_analysis_blast_submit_jobs($node);
}

/*******************************************************************************
 *  The following function proves access control for users trying to
*  perform actions on data managed by this module
*/
function chado_analysis_blast_access($op,  $node, $account) {
  $node_type = $node;
  if (is_object($node)) {
    $node_type = $node->type;
  }
  
  if($node_type == 'chado_analysis_blast') {
    if ($op == 'create') {
      if (!user_access('create chado_analysis_blast content',   $account)) {
        return NODE_ACCESS_DENY;
      }
      return NODE_ACCESS_ALLOW;
    }
    if ($op == 'update') {
      if (!user_access('edit chado_analysis_blast content',   $account)) {
        return NODE_ACCESS_DENY;
      }
    }
    if ($op == 'delete') {
      if (!user_access('delete chado_analysis_blast content',   $account)) {
        return NODE_ACCESS_DENY;
      }
    }
    if ($op == 'view') {
      if (!user_access('access chado_analysis_blast content',   $account)) {
        return NODE_ACCESS_DENY;
      }
    }
    return NODE_ACCESS_IGNORE;
  }
}
/*******************************************************************************
 * Delete blast anlysis
*/
function chado_analysis_blast_delete($node) {
  chado_analysis_delete($node);
} 