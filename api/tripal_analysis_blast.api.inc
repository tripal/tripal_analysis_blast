<?php

/**
 * Get blast result for a given feature as stored by this module in the
 * analysisfeature_prop table
 * 
 * @param $feature_id
 *   The feature_id of the feature
 * @param $db_id
 *   Optional.  Specify a database id.  This is useful if a feature was
 *   blast'ed against multipe databases and you only need to retrieve 
 *   results for a specific set.
 */
function tripal_get_feature_blast_results($feature_id, $db_id) {

  // Get the blast results stored as XML from the analysisfeatureprop table
  // the type for the property is named 'analysis_blast_output_iteration_hits'
  // and is found in the 'tripal' controlled vocabulary.  This CV term was
  // added by this module.
  $select = array(
    'analysisfeature_id' => array(
      'feature_id' => $feature_id,
    ),
    'type_id' => array(
      'name' => 'analysis_blast_output_iteration_hits',
      'cv_id' => array(
        'name' => 'tripal'
      ),
    ),
  );
  $blast_results = tripal_core_chado_select('analysisfeatureprop', array('*'), $select);
  if (!$blast_results) {
    return;
  }

  // get the HTML content for viewing each of the XML file
  $blast_obj_array = array();
  $blast_obj_counter = 0;
  foreach ($blast_results as $index => $analysisfeatureprop) {
    // get the blast XML for this feature
    $blast_xml = $analysisfeatureprop->value;

    // get the analysis record
    $analysisfeature_arr = tripal_core_chado_select('analysisfeature', array('analysis_id'),
        array('analysisfeature_id' => $analysisfeatureprop->analysisfeature_id));
    $analysis_arr = tripal_core_chado_select('analysis', array('*'),
        array('analysis_id' => $analysisfeature_arr[0]->analysis_id));
    $analysis = $analysis_arr[0];
    $analysis_id = $analysis->analysis_id;

    // the old style was to store all parameters in a single CV term in the analysisprop
    // table. However now each property has it's own CV term in that table. But,
    // we still need to support the old method for backwards compatibility.
    // so,  first get the old style variable and see if it has values. In
    // particular we need the database setting
    $blast_settings  = tripal_analysis_get_property($analysis_id, 'analysis_blast_settings');
    if ($blast_settings) {
      $blastsettings = explode("|",   $blast_settings->value);
      // if we don't have the proper number of fields in the value column then
      // skip this entry
      if (count($blastsettings) != 3) {
        continue;
      }
      $adb_id = $blastsettings[0];
    }
    // if we're not using the old style then try the new method to get the
    // database id
    else {
      $blastdb = tripal_analysis_get_property($analysis_id, 'analysis_blast_blastdb');
      $adb_id = $blastdb->value;
    }
    // if the callee specified a database to show then we want to check that
    // with the database id of the analysis we're looking at.  If they
    // don't match then skip this blast.  If a database id was not specified
    // then continue
    if ($db_id and $adb_id != $db_id) {
      continue;
    }

    // get the database
    if ($adb_id) {
      $db_arr = tripal_core_chado_select('db', array('*'), array('db_id' => $adb_id));
      $db = $db_arr[0];
    }

    // parse the XML and add it to the array of blast results to be returned
    $blast_obj = tripal_analysis_blast_get_result_object($blast_xml, $db, $feature_id, $analysis);
    $blast_obj->analysis = $analysis;
    $blast_obj_array [$blast_obj_counter] = $blast_obj;
    $blast_obj_counter ++;
  }

  return $blast_obj_array;
}