<?php

class format__blast_results_formatter extends ChadoFieldFormatter {

  // The default lable for this field.
  public static $default_label = 'BLAST Results';

  // The list of field types for which this formatter is appropriate.
  public static $field_types = ['format__blast_results'];

  /**
   * @see TripalFieldFormatter::view()
   */
  public function view(&$element, $entity_type, $entity, $langcode, $items, $display) {

    if (count($items) == 0 or (count($items) == 1 and empty($items[0]['value']))) {
      $element[0] = [
        '#type' => 'markup',
        '#markup' => 'There are no blast results.',
      ];
      return;
    }

    $element[0]['preamble'] = ['#markup' => "<p>Click a tab below to view blast alignments.</p>"];

    drupal_add_js("https://cdn.rawgit.com/calipho-sib/feature-viewer/v1.0.4/dist/feature-viewer.bundle.js", [
      'type' => 'external',
      'scope' => 'header',
      'group' => 15,
      'every_page' => TRUE,
      'weight' => 500,
    ]);


    // Get the settings
    $settings = $display['settings'];
    $feature = $entity->chado_record;
    $feature = chado_expand_var($feature, 'field', 'feature.residues');

    $num_results_per_page = 10000;
    $feature_pager_id = 15; // increment by 1 for each database
    $i = 0;
    $list_items = [];
    $total = count($items);

    foreach ($items as $delta => $item) {
      if (!isset($item['parsed'])) {
        continue;
      }
      $blast_result = $item['parsed'];

      $hits_array = $blast_result->hits_array;
      $db = $blast_result->db;
      $analysis = $blast_result->analysis;
      $pager_id = $feature_pager_id + $i;
      $total_records = count($hits_array);

      if (empty($db->name)) {
        $db->name = "Database not found";
      }

      $current_page_num = pager_default_initialize($total_records, $num_results_per_page, $pager_id);
      $offset = $num_results_per_page * $current_page_num;

      // add this blast report to the list of available reports
      $result_name = "BLAST of " . $feature->name . " vs. " . $db->name . ' (' . preg_replace('/(.*)\s.*/', '\1', $analysis->timeexecuted) . ')';
      $list_items[] = "<a href=\"#analysis-" . $analysis->analysis_id . "\">$result_name</a>";

      $analysis_link = $analysis->name;
      if (property_exists($analysis, 'nid')) {
        $analysis_link = l($analysis->name, 'node/' . $analysis->nid);
      }
      if (property_exists($analysis, 'entity_id')) {
        $analysis_link = l($analysis->name, 'bio_data/' . $analysis->entity_id);
      }

      $element[0][$i] = [
        '#type' => 'fieldset',
        '#title' => $analysis->name,
        '#collapsed' => TRUE,
        '#collapsible' => TRUE,
        '#attached' => [
          'library' => [
            ['system', 'drupal.collapse'],
          ],
        ],
        '#attributes' => [
          'class' => ['collapsible', 'collapsed'],
        ],
      ];

      $content = '
          <p>
            Performed on: ' . preg_replace("/^(\d+-\d+-\d+) .*/", "$1", $analysis->timeexecuted) . '
            <br>Details: ' . $analysis_link . '
            <br>Display provided by the ' . l('neXtProt Feature Viewer', 'https://github.com/calipho-sib/feature-viewer') . '
            <div
              id="features_vis-' . $analysis->analysis_id . '"
              class="blast-alignment-viewer"
              analysis_id="' . $analysis->analysis_id . '" 
              residues="' . $feature->residues . '"
              width="' . variable_get('tripal_blast_results_viewer_size', 500) . 'px"></div>
            <br><a id="tripal-analysis-blast-table-link-' . $analysis->analysis_id . '" class="tripal-analysis-blast-table-link" href="javascript:void(0)">Show Results Table</a>
          </p>
      ';

      $element[0][$i]['content'] = ['#markup' => $content];
      $headers = ['Match Name', 'Summary'];
      $rows = [];

      $content = '';

      // iterate through the records to display on this page for this analysis.
      // We have access to all of them but we use a pager to display just a subset.
      for ($j = $offset; $j < $offset + $num_results_per_page && $j < $total_records; $j++) {
        $hit = $hits_array[$j];
        $hit_name = $hit['hit_name'];
        if (array_key_exists('hit_url', $hit)) {
          $hit_name = l($hit_name, $hit['hit_url'], ['attributes' => ['target' => '_blank']]);
        }

        $description = substr($hit['description'], 0, 150);
        if (strlen($hit['description']) > 150) {
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
          <div class="tripal-analysis-blast-info-hsp-desc" id="hsp-desc-' . $analysis->analysis_id . '-' . $j . '">
            <div class="hsp-desc-close"><a href="javascript:void(0)">[Close]</a></div>
            <strong>BLAST of ' . $feature->name . ' vs. ' . $db->name . '</strong>
            <br>Match Name: ' . $hit_name . '
            <br>Description: ' . $hit['description'] . '<br>';
        foreach ($hsps_array AS $hsp) {
          $content .= '
            <br><b>HSP ' . $hsp['hsp_num'] . '</b> Score: ' . $hsp['bit_score'] . ' bits (' . $hsp['score'] . '), Expect = ' . sprintf('%.3e', floatval($hsp['evalue'])) . '<br>Identity = ' . sprintf("%d/%d (%.2f%%)", $hsp['identity'], $hsp['align_len'], $hsp['identity'] / $hsp['align_len'] * 100) . ', Postives = ' . sprintf("%d/%d (%.2f%%)", $hsp['positive'], $hsp['align_len'], $hsp['positive'] / $hsp['align_len'] * 100) . ', Query Frame = ' . $hsp['query_frame'] . '
            <pre class="blast_align">' . "\n" .
            'Query: ' . sprintf("%8d", $hsp['query_from']) . ' ' . $hsp['qseq'] . ' ' . sprintf("%d", $hsp['query_to']) . "\n" .
            '                ' . $hsp['midline'] . "\n" .
            'Sbjct: ' . sprintf("%8d", $hsp['hit_from']) . ' ' . $hsp['hseq'] . ' ' . sprintf("%d", $hsp['hit_to']) . '
            </pre>';


          // Get the start/stop and description info for the feature viewer.
          $feature_viewer_data[] = [
            'x' => intval($hsp['query_from']),
            'y' => intval($hsp['query_to']),
            'id' => 'tripal-analysis-blast-hsp-' . $analysis->analysis_id . '-' . $j,
            'description' => 'E-value:  ' . sprintf('%.2e', floatval($hsp['evalue'])) . ', PID: ' . $hit['percent_identity'],
          ];
        }
        // End the HSP div.
        $content .= '</div>';

        $element[0][$i]['Blast_description'] = ['#markup' => $content];

        $rows[] = [
          'data' => [
            $hit_name,
            '<strong>E-Value</strong>: ' . $evalue . ', <strong>PID</strong>: ' . $pid . '<br>' .
            // we want a tooltip if the user mouses-over a description to show the entire thing
            '<strong>Description</strong>: <span title="' . $hit['description'] . '">' . $description . '</span> ' .
            // add the [more] link at the end of the row.  Javascript is used
            // to display this record
            '<a href="javascript:void(0);" id="hsp-link-' . $analysis->analysis_id . '-' . $j . '" class="tripal-analysis-blast-info-hsp-link">[more]</a>',
          ],
          // Add data as attributes. These will be used for adding the hit
          // to the feature viewer.
          'class' => ["blast-hits-" . $analysis->analysis_id],
          'fv_class' => 'tripal-analysis-blast-hsp',
          'hit_name' => $hit['hit_name'],
          'fv_data' => json_encode($feature_viewer_data),
        ];
      }

      // For information on rows, headers, etc, see https://api.drupal.org/api/drupal/includes%21theme.inc/function/theme_table/7.x
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
      $themed_table = theme_table($table);

      // Add the pager. see:
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

      $themed_table .= $this->ajaxifyPager($pager, $entity);

      $element[0][$i]['table'] = ['#markup' => $themed_table];

      $i++;
    }
    // End items.

    drupal_add_css(drupal_get_path('module', 'tripal_analysis_blast') . "/theme/css/tripal_analysis_blast.css");
    drupal_add_js(drupal_get_path('module', 'tripal_analysis_blast') . "/theme/js/tripal_analysis_blast.tv3.js");
  }
}
