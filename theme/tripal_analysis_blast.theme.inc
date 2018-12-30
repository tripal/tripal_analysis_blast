<?php

/**
 *
 */
function tripal_analysis_blast_preprocess_tripal_feature_blast_results(&$variables) {
    $feature = $variables['node']->feature;
    $db_id = null;
    //$db_id = $variables['node']->db_id; // this value only gets set on an ajax call

    $blast_results = tripal_get_feature_blast_results($feature->feature_id,  $db_id);
    $feature->tripal_analysis_blast = new stdClass;
    $feature->tripal_analysis_blast->blast_results_list = $blast_results;

    drupal_add_css(drupal_get_path('module', 'tripal_analysis_blast') . "/theme/css/tripal_analysis_blast.css");
    drupal_add_js(drupal_get_path('module', 'tripal_analysis_blast') . "/theme/js/tripal_analysis_blast.js");

    drupal_add_js("https://cdn.rawgit.com/calipho-sib/feature-viewer/v1.0.0/dist/feature-viewer.bundle.js", [
      'type' => 'external',
      'scope' => 'header',
      'group' => 15,
      'every_page' => TRUE,
      'weight' => 500,
    ]);


    // Hack to ensure that jquery 3.1.0 is loaded just before calling noconflict
    /*
    drupal_add_js("https://cdnjs.cloudflare.com/ajax/libs/jquery/3.1.0/jquery.min.js", array(
      'type' => 'external',
      'scope' => 'header',
      'group' => 15,
      'every_page' => TRUE,
      'weight' => 100,
    ));
    drupal_add_js("https://cdnjs.cloudflare.com/ajax/libs/d3/3.5.17/d3.min.js", array(
      'type' => 'external',
      'scope' => 'header',
      'group' => 15,
      'every_page' => TRUE,
      'weight' => 200,
    ));
    drupal_add_js("https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.3.7/js/bootstrap.min.js", array(
      'type' => 'external',
      'scope' => 'header',
      'group' => 15,
      'every_page' => TRUE,
      'weight' => 300,
    ));
    drupal_add_js("https://cdn.rawgit.com/calipho-sib/feature-viewer/v0.1.44/dist/feature-viewer.min.js", array(
      'type' => 'external',
      'scope' => 'header',
      'group' => 15,
      'every_page' => TRUE,
      'weight' => 400,
    ));
    drupal_add_js("if (typeof feature_viewer_jquery == 'undefined') {var feature_viewer_jquery = jQuery.noConflict(true);}", array(
      'type' => 'inline',
      'scope' => 'header',
      'group' => 15,
      'every_page' => TRUE,
      'weight' => 1000,
    ));*/
}
