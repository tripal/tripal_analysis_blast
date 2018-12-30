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

    if (count($items) == 0 or (count($items) == 1 and empty($items[0]['value']))) {
      $element[0] = array(
        '#type' => 'markup',
        '#markup' => 'There are no blast results.',
      );
      return;
    }

    drupal_add_js("https://cdn.rawgit.com/calipho-sib/feature-viewer/v1.0.0/dist/feature-viewer.bundle.js", [
      'type' => 'external',
      'scope' => 'header',
      'group' => 15,
      'every_page' => TRUE,
      'weight' => 500,
    ]);

    // We want JQuery UI support for the accordion.
    drupal_add_library('system','ui.accordion');

    // Get the settings
    $settings = $display['settings'];
    $feature = $entity->chado_record;
    $feature = chado_expand_var($feature, 'field', 'feature.residues');


    $content = "<p>Click a tab below to view blast alignments.</p>";
    $content .= '<div id="tripal-blast-analysis-accordian">';

    $num_results_per_page = 25;
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

      if(empty($db->name)) {
        $db->name = "Database not found";
      }

      $current_page_num = pager_default_initialize($total_records, $num_results_per_page, $pager_id);
      $offset = $num_results_per_page * $current_page_num;

      // add this blast report to the list of available reports
      $result_name = "BLAST of " . $feature->name . " vs. " . $db->name . ' (' . preg_replace('/(.*)\s.*/','\1', $analysis->timeexecuted) . ')';
      $list_items[] = "<a href=\"#analysis-" . $analysis->analysis_id . "\">$result_name</a>";

      $analysis_link = $analysis->name;
      if (array_key_exists('nid', $analysis)) {
        $analysis_link = l($analysis->name, 'node/' . $analysis->nid);
      }
      if (array_key_exists('entity_id', $analysis)) {
        $analysis_link = l($analysis->name, 'bio_data/' . $analysis->entity_id);
      }

      // add the div box for each blast results
      $content .= '
        <h3 id="analysis-' . $analysis->analysis_id . '">' . $result_name . '</h3>
        <div>
          <p>
            Performed on: ' .  preg_replace("/^(\d+-\d+-\d+) .*/", "$1", $analysis->timeexecuted) . '
            <br>Details: ' . $analysis_link . '
            <br>Display provided by the ' . l('neXtProt Feature Viewer' , 'https://github.com/calipho-sib/feature-viewer') . '
            <div
              id="features_vis-' . $analysis->analysis_id . '"
              class="blast-alignment-viewer"
              analysis_id="' . $analysis->analysis_id . '" residues="' . $feature->residues . '"
              width="' . variable_get('tripal_blast_results_viewer_size', 500) . 'px"></div>
            <br><a id="tripal-analysis-blast-table-link-' . $analysis->analysis_id . '" class="tripal-analysis-blast-table-link" href="javascript:void(0)">Show Results Table</a>
      ';

      // the $headers array is an array of fields to use as the colum headers.
      // additional documentation can be found here
      // https://api.drupal.org/api/drupal/includes%21theme.inc/function/theme_table/7
      $headers = array('Match Name', 'Stats', 'Description', 'Details');

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

        // Create an array containing the data for the feature viewer.
        $feature_viewer_data = [];

        // Add a div box for each HSP details.  This box will be invisible by default
        // and made visible when the '[more]' link is clicked
        $hsps_array = $hit['hsp'];
        $content .= '
          <div class="tripal-analysis-blast-info-hsp-desc" id="hsp-desc-' . $analysis->analysis_id . '-' .  $j . '">
            <div class="hsp-desc-close"><a href="javascript:void(0)" onclick="this.hide()">[Close]</a></div>
            <strong>BLAST of ' . $feature->name . ' vs. ' . $db->name . '</strong>
            <br>Match Name: ' . $hit_name . '
            <br>Description: ' . $hit['description'] . '<br>';
        foreach ($hsps_array AS $hsp) {
          $content .= '
            <br><b>HSP ' . $hsp['hsp_num'] . '</b> Score: ' . $hsp['bit_score'] . ' bits (' . $hsp['score'] . '), Expect = ' . sprintf('%.3e', floatval($hsp['evalue'])) . '<br>Identity = ' .  sprintf("%d/%d (%.2f%%)", $hsp['identity'], $hsp['align_len'], $hsp['identity']/$hsp['align_len']*100) . ', Postives = ' .  sprintf("%d/%d (%.2f%%)", $hsp['positive'], $hsp['align_len'], $hsp['positive']/$hsp['align_len']*100) . ', Query Frame = ' . $hsp['query_frame'] . '
            <pre class="blast_align">' . "\n" .
              'Query: ' . sprintf("%8d", $hsp['query_from']) . ' ' . $hsp['qseq'] . ' ' . sprintf("%d", $hsp['query_to'])  . "\n" .
              '                ' . $hsp['midline'] . "\n" .
              'Sbjct: ' . sprintf("%8d", $hsp['hit_from']) . ' ' . $hsp['hseq'] . ' ' . sprintf("%d",$hsp['hit_to'])  . '
            </pre>';

          // Get the start/stop and description info for the feature viewer.
          $feature_viewer_data[] = array(
            'x' => intval($hsp['query_from']),
            'y' => intval($hsp['query_to']),
            'id' => 'tripal-analysis-blast-hsp-' . $analysis->analysis_id . '-' .  $j,
            'description' => 'E-value:  ' . sprintf('%.2e', floatval($hsp['evalue'])) . ', PID: ' . $hit['percent_identity']
          );
        }
        // End the HSP div.
        $content .= '</div>';

        $rows[] = [
          'data' => [
            $hit_name,
            'E-Value: ' . $evalue . '<br>PID: ' . $pid,
            // we want a tooltip if the user mouses-over a description to show the entire thing
            '<span title="' . $hit['description'] . '">' . $description . '</span>',
            // add the [more] link at the end of the row.  Javascript is used
            // to display this record
            '<a href="javascript:void(0);" id="hsp-link-' . $analysis->analysis_id . '-' .  $j . '" class="tripal-analysis-blast-info-hsp-link">details</a>',
          ],
          // Add data as attributes. These will be used for adding the hit
          // to the feature viewer.
          'class' => ["blast-hits-" . $analysis->analysis_id],
          'fv_class' => 'tripal-analysis-blast-hsp',
          'hit_name' => $hit['hit_name'],
          'fv_data' => json_encode($feature_viewer_data),
        ];
      }

      // the $table array contains the headers and rows array as well as other
      // options for controlling the display of the table.  Additional
      // documentation can be found here:
      // https://api.drupal.org/api/drupal/includes%21theme.inc/function/theme_table/7
      $table = [
        'header' => $headers,
        'rows' => $rows,
        'attributes' => [
          'id' => 'tripal-analysis-blast-table-' . $analysis->analysis_id,
          'class' => ['tripal-analysis-blast-table'],
        ],
        'sticky' => FALSE,
        'caption' => '',
        'colgroups' => [],
        'empty' => "There are no matches against " . $db->name . " for this " . $feature->type_id->name . ".",
      ];

      // once we have our table array structure defined, we call Drupal's theme_table()
      // function to generate the table.
      $content .= theme_table($table);

      // the $pager array values that control the behavior of the pager.  For
      // documentation on the values allows in this array see:
      // https://api.drupal.org/api/drupal/includes!pager.inc/function/theme_pager/7
      // here we add the parameter 'block' => 'features'. This is because
      // the pager is not on the default block that appears. When the user clicks a
      // page number we want the browser to re-appear with the page is loaded.
      $pager = theme('pager', [
        'tags' => [],
        'element' => $pager_id,
        'parameters' => [
          'block' => 'homology',
        ],
        'quantity' => $num_results_per_page,
      ]);

      $content .= $this->ajaxifyPager($pager, $entity);
      // End the paragraph for this item in the accordion.
      $content .= '</p>';
      // End the div for this item in the accordion.
      $content .= '</div>';

      $i++;
    }

    // End the accordion.
    $content .= '</div>';

    $element[0] = [
      '#type' => 'markup',
      '#markup' => $content,
    ];
    /* // Add a list at the top of the field.
    $content = '<div class="tripal_feature-data-block-desc tripal-data-block-desc">The following BLAST results are available for this feature:</div> ';
    $content .= '<a name="blast-results-top"></a>';
    $content .= theme_item_list([
      'items' => $list_items,
      'title' => '',
      'type' => 'ul',
      'attributes' => [],
    ]);
    if (key_exists(0, $element)) {
      $element[0] = [
        '#type' => 'markup',
        '#markup' => $content . $element[0]['#markup'],
      ];
    } */
  }
}
