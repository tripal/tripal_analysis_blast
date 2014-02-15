<?php

/*******************************************************************************
 *
*/
function tripal_analysis_blast_preprocess_tripal_feature_blast_results(&$variables) {
  $feature = $variables['node']->feature;
  $db_id = null;
  //$db_id = $variables['node']->db_id; // this value only gets set on an ajax call

  $blast_results = tripal_get_feature_blast_results($feature->feature_id,  $db_id);
  $feature->tripal_analysis_blast = new stdClass;
  $feature->tripal_analysis_blast->blast_results_list = $blast_results;
}