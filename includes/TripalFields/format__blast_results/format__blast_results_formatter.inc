<?php

class format__blast_results_formatter extends ChadoFieldFormatter {

  // The default lable for this field.
  public static $default_label = 'BLAST Results';

  // The list of field types for which this formatter is appropriate.
  public static $field_types = array('format__blast_results');

  /**
   * @see TripalFieldFormatter::view()
   */
  public function view(&$element, $entity_type, $entity, $langcode, $items, $display) {

    if (count($items) == 0) {
      $element[0] = array(
        '#type' => 'markup',
        '#markup' => 'There are no blast results.',
      );
      return;
    }
    // Hack to ensure that jquery 3.1.0 is loaded just before calling noconflict
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
    ));

    // Get the settings
    $settings = $display['settings'];
    $feature = $entity->chado_record;
    $feature = chado_expand_var($feature, 'field', 'feature.residues');

    $num_results_per_page = 10;
    $feature_pager_id = 15; // increment by 1 for each database
    $i = 0;
    $list_items = array();
    foreach ($items as $delta => $item) {
      if (!isset($item['parsed'])) {
        continue;
      }
      $blast_result = $item['parsed'];

      $hits_array    = $blast_result->hits_array;
      $db            = $blast_result->db;
      $analysis      = $blast_result->analysis;
      $pager_id      = $feature_pager_id + $i;
      $total_records = count($hits_array);

      $current_page_num = pager_default_initialize($total_records, $num_results_per_page, $pager_id);
      $offset = $num_results_per_page * $current_page_num;

      // add this blast report to the list of available reports
      $result_name = "BLAST of " . $feature->name . " vs. " . $db->name;
      $list_items[] = "<a href=\"#" . $analysis->analysis_id . "\">$result_name</a>";

      $analysis_link = $analysis->name;
      if (array_key_exists('nid', $analysis) {
        $analysis_link = l($analysis->name, 'node/' . $analysis->nid);
      }
      if (array_key_exists('entity_id', $analysis) {
        $analysis_link = l($analysis->name, 'bio_data/' . $analysis->entity_id);
      }

      // add the div box for each blast results
      $content = '
        <div  id="' . $analysis->analysis_id . '">
          <strong>' . $result_name . '</strong>
          <br>Analysis Date: ' .  preg_replace("/^(\d+-\d+-\d+) .*/", "$1", $analysis->timeexecuted) . '
          <br>Analysis Name: ' . $analysis_link . '
          <br>Total hits: ' . number_format($total_records) . '
          <div id="features_vis-' . $analysis->analysis_id . '"></div>
          <script lang="javascript">
            (function($) {
              // hack to work with multiple jquery versions
              backup_jquery = window.jQuery;
              window.jQuery = $;
              window.$ = $;
              window.ft' . $analysis->analysis_id . ' = new FeatureViewer("' . $feature->residues . '", "#features_vis-' . $analysis->analysis_id . '",
              {
                  showAxis: true,
                  showSequence: true,
                  brushActive: true, //zoom
                  toolbar:true, //current zoom & mouse position
                  bubbleHelp:false,
                  zoomMax:3 //define the maximum range of the zoom
              });
              window.jQuery = backup_jquery;
              window.$ = backup_jquery;
            })(feature_viewer_jquery);
          </script>
      ';

      // the $headers array is an array of fields to use as the colum headers.
      // additional documentation can be found here
      // https://api.drupal.org/api/drupal/includes%21theme.inc/function/theme_table/7
      $headers = array('Match Name', 'Stats', 'Description');

      // the $rows array contains an array of rows where each row is an array
      // of values for each column of the table in that row.  Additional documentation
      // can be found here:
      // https://api.drupal.org/api/drupal/includes%21theme.inc/function/theme_table/7
      $rows = array();

      // iterate through the records to display on this page. We have access to all of them
      // but we use a pager to display just a subset.
      for ($j = $offset; $j < $offset + $num_results_per_page && $j < $total_records; $j++) {
        $hit = $hits_array[$j];
        $hit_name = $hit['hit_name'];
        if (array_key_exists('hit_url', $hit)) {
          $hit_name = l($hit_name, $hit['hit_url'], array('attributes' => array('target' => '_blank')));
        }

        $description = substr($hit['description'], 0, 50);
        if (strlen($hit['description']) > 50) {
          $description = $description . "... ";
        }

        $evalue = sprintf("%.3e", $hit['best_evalue']);
        $pid = array_key_exists('percent_identity', $hit) ? $hit['percent_identity'] : '';
        $rows[] = array(
          $hit_name,
          'E-Value: ' . $evalue . ', PID: ' . $pid,
          // we want a tooltip if the user mouses-over a description to show the entire thing
          '<span title="' . $hit['description'] . '">' . $description . '</span>' .
          // add the [more] link at the end of the row.  Javascript is used
          // to display this record
          '<a href="javascript:void(0);" id="hsp-link-' . $analysis->analysis_id . '-' .  $j . '" class="tripal-analysis-blast-info-hsp-link">[more]</a>',
        );

        // add a div box for each HSP details.  This box will be invisible by default
        // and made visible when the '[more]' link is clicked
        $hsps_array = $hit['hsp'];
        $content .= '
          <div class="tripal-analysis-blast-info-hsp-desc" id="hsp-desc-' . $analysis->analysis_id . '-' .  $j . '">
            <div class="hsp-desc-close"><a href="javascript:void(0)" onclick="this.hide()">[Close]</a></div>
            <strong>BLAST of ' . $feature->name . ' vs. ' . $db->name . '</strong>
            <br>Match: ' . $hit_name . ' (' . $hit['description'] . ')<br>
        ';
        $hsp_pos = array();
        foreach ($hsps_array AS $hsp) {
          $content .= '
            <br><b>HSP ' . $hsp['hsp_num'] . '</b> Score: ' . $hsp['bit_score'] . ' bits (' . $hsp['score'] . '), Expect = ' . sprintf('%.3e', floatval($hsp['evalue'])) . '<br>Identity = ' .  sprintf("%d/%d (%.2f%%)", $hsp['identity'], $hsp['align_len'], $hsp['identity']/$hsp['align_len']*100) . ', Postives = ' .  sprintf("%d/%d (%.2f%%)", $hsp['positive'], $hsp['align_len'], $hsp['positive']/$hsp['align_len']*100) . ', Query Frame = ' . $hsp['query_frame'] . '
            <pre class="blast_align">
              Query: ' . sprintf("%4d", $hsp['query_from']) . ' ' . $hsp['qseq'] . ' ' . sprintf("%d", $hsp['query_to']) . ' ' . $hsp['midline'] . '
              Sbjct: ' . sprintf("%4d", $hsp['hit_from']) . ' ' . $hsp['hseq'] . ' ' . sprintf("%d",$hsp['hit_to'])  . '
            </pre>';
          $hsp_pos[] = array(
            'x' => intval($hsp['query_from']),
            'y' => intval($hsp['query_to']),
            'description' => 'Expect = ' . sprintf('%.2e', floatval($hsp['evalue'])) . ', Id = ' . $hit['percent_identity']
          );
        }

        // In case the hitname is a long id, simplify it
        $clean_hit_name = preg_replace("/^([a-zA-Z0-9._-]+)\|([a-zA-Z0-9._-]+)\|([a-zA-Z0-9._-]+)\|([a-zA-Z0-9._-]+)\|$/", "$4", $hit['hit_name']);
        $content .= '
          <script lang="javascript">
            (function($) {
              // hack to work with multiple jquery versions
              backup_jquery = window.jQuery;
              window.jQuery = $;
              window.$ = $;
              window.ft' . $analysis->analysis_id . '.addFeature({
                 data: ' . json_encode($hsp_pos) . ',
                 name: "' . $clean_hit_name . '",
                 className: "blast_match", //can be used for styling
                 color: "#0F8292",
                 type: "rect"
             });
             window.jQuery = backup_jquery;
             //window.$ = backup_jquery;
           })(feature_viewer_jquery);
          </script>
        </div>';
      }

      // if there are no results then print a nice message
      if ($total_records == 0) {
        $row[] = array(
          array(
            'data' => "There are no matches against " . $db->name . " for this " . $feature->type_id->name . ".",
            'colspan' => '5'
          ),
        );
      }

      // the $table array contains the headers and rows array as well as other
      // options for controlling the display of the table.  Additional
      // documentation can be found here:
      // https://api.drupal.org/api/drupal/includes%21theme.inc/function/theme_table/7
      $table = array(
        'header' => $headers,
        'rows' => $rows,
        'attributes' => array(
          'id' => 'tripal_feature-table-blast_results',
        ),
        'sticky' => FALSE,
        'caption' => '',
        'colgroups' => array(),
        'empty' => '',
      );

      // once we have our table array structure defined, we call Drupal's theme_table()
      // function to generate the table.
      $content .= theme_table($table);

      // the $pager array values that control the behavior of the pager.  For
      // documentation on the values allows in this array see:
      // https://api.drupal.org/api/drupal/includes!pager.inc/function/theme_pager/7
      // here we add the paramter 'block' => 'features'. This is because
      // the pager is not on the default block that appears. When the user clicks a
      // page number we want the browser to re-appear with the page is loaded.
      $pager = array(
        'tags' => array(),
        'element' => $pager_id,
        'parameters' => array(
          'block' => 'homology',
        ),
        'quantity' => $num_results_per_page,
      );
      $content .= theme_pager($pager);
      $content .= '<a href="#blast-results-top">back to top</a>';
      $content .= '</div>';

      $element[$delta] = array(
        '#type' => 'markup',
        '#markup' => $content,
      );
      $i++;
    }
    // once we have our table array structure defined, we call Drupal's theme_table()
    // function to generate the table.
    if (count($items) == 0) {
      $element[0] = array(
        '#type' => 'markup',
        '#markup' => 'There are no BLAST results for this feature.',
      );
    }
    else {
      // Add a list at the top of the field.
      $content = '<div class="tripal_feature-data-block-desc tripal-data-block-desc">The following BLAST results are available for this feature:</div> ';
      $content .= '<a name="blast-results-top"></a>';
      $content .= theme_item_list(array(
        'items' => $list_items,
        'title' => '',
        'type' => 'ul',
        'attributes' => array(),
      ));
      if(key_exists(0, $element)) {
        $element[0] = array(
          '#type' => 'markup',
          '#markup' => $content . $element[0]['#markup'],
        );
      }
    }
  }
}
