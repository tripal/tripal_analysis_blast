<?php
$feature = $variables['node']->feature;
$feature = chado_expand_var($feature,'field','feature.residues');
$blast_results_list = $feature->tripal_analysis_blast->blast_results_list;

// specify the number of records to show per database
$num_results_per_page = 10;
$feature_pager_id = 15; // increment by 1 for each database

$results_html = '';    // a variable for holding all blast HTML text
$list_items = array(); // a list to be used for theming of content on this page

if(count($blast_results_list) > 0){
  $i = 0;

  foreach ($blast_results_list as $blast_result) {
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

    // add the div box for each blast results
    $results_html .= "
      <div class=\"tripal-info-box-desc tripal_analysis_blast-info-box-desc\" id=\"" . $analysis->analysis_id . "\">
        <strong>" . $result_name . "</strong>
        <br>Analysis Date: " .  preg_replace("/^(\d+-\d+-\d+) .*/","$1", $analysis->timeexecuted) . " (<a href=" . url("node/$analysis->nid").">$analysis->name</a>)
        <br>Total hits: " . number_format($total_records) . "
      <div id=\"features_vis-".$analysis->analysis_id."\"></div>
      <script lang=\"javascript\">
          var ft".$analysis->analysis_id." = new FeatureViewer('".$feature->residues."',
          '#features_vis-".$analysis->analysis_id."',
          {
              showAxis: true,
              showSequence: true,
              brushActive: true, //zoom
              toolbar:true, //current zoom & mouse position
              bubbleHelp:true,
              zoomMax:3 //define the maximum range of the zoom
          });
      </script>
  ";
  ?>

    <?php
    // the $headers array is an array of fields to use as the colum headers.
    // additional documentation can be found here
    // https://api.drupal.org/api/drupal/includes%21theme.inc/function/theme_table/7
    $headers = array('Match Name', 'E-value', 'Identity', 'Description', '');

    // the $rows array contains an array of rows where each row is an array
    // of values for each column of the table in that row.  Additional documentation
    // can be found here:
    // https://api.drupal.org/api/drupal/includes%21theme.inc/function/theme_table/7
    $rows = array();

    // iterate through the records to display on this page. We have access to all of them
    // but we use a pager to display just a subset.
    for ($j = $offset ; $j < $offset + $num_results_per_page && $j < $total_records; $j++) {
      $hit = $hits_array[$j];
      $hit_name = $hit['hit_name'];
      if (array_key_exists('hit_url', $hit)) {
        $hit_name = l($hit_name, $hit['hit_url'], array('attributes' => array('target' => '_blank')));
      }

      $description = substr($hit['description'], 0, 50);
      if (strlen($hit['description']) > 50) {
        $description = $description . "... ";
      }

      $rows[] = array(
        $hit_name,
        sprintf("%.3e", $hit['best_evalue']),
        array_key_exists('percent_identity', $hit) ? $hit['percent_identity'] : '',
        // we want a tooltip if the user mouses-over a description to show the entire thing
        '<span title="' . $hit['description'] . '">' . $description . '</span>',
        // add the [more] link at the end of the row.  Javascript is used
        // to display this record
        "<a href=\"javascript:void(0);\"
          id=\"hsp-link-" . $analysis->analysis_id . "-" .  $j ."\"
          class=\"tripal-analysis-blast-info-hsp-link\"
          \">[more]</a>",
      );

      // add a div box for each HSP details.  This box will be invisible by default
      // and made visible when the '[more]' link is clicked
      $hsps_array = $hit['hsp']; ?>

      <div class="tripal-analysis-blast-info-hsp-desc" id="hsp-desc-<?php print $analysis->analysis_id ?>-<?php print $j?>">
        <div class="hsp-desc-close"><a href="javascript:void(0)" onclick="this.hide()">[Close]</a></div>
        <strong>BLAST of <?php print $feature->name ?> vs. <?php print $db->name?></strong>
        <br>Match: <?php print $hit_name . " (" . $hit['description'] . ")<br>";
        $hsp_pos = array();
        foreach ($hsps_array AS $hsp) { ?>
          <br><b>HSP <?php  print $hsp['hsp_num'] ?></b> Score: <?php print $hsp['bit_score'] ?> bits (<?php print $hsp['score'] ?>), Expect = <?php print sprintf('%.3e', floatval($hsp['evalue'])) ?><br>Identity = <?php print sprintf("%d/%d (%.2f%%)", $hsp['identity'], $hsp['align_len'], $hsp['identity']/$hsp['align_len']*100) ?>, Postives = <?php print sprintf("%d/%d (%.2f%%)", $hsp['positive'], $hsp['align_len'], $hsp['positive']/$hsp['align_len']*100)?>, Query Frame = <?php print $hsp['query_frame']?>
          <pre class="blast_align">
Query: <?php print sprintf("%4d", $hsp['query_from'])?> <?php print $hsp['qseq'] ?> <?php print sprintf("%d", $hsp['query_to']); ?>

            <?php print $hsp['midline'] ?>

Sbjct: <?php print sprintf("%4d", $hsp['hit_from']) ?> <?php print $hsp['hseq']?> <?php print sprintf("%d",$hsp['hit_to']) ?>
          </pre><?php
          $hsp_pos[] = array('x' => intval($hsp['query_from']), 'y' => intval($hsp['query_to']), 'description' => 'Expect = '.sprintf('%.2e', floatval($hsp['evalue'])).' / Id = '.$hit['percent_identity']);
        }
        // In case the hitname is a long id, simplify it
        $clean_hit_name = preg_replace("/^([a-zA-Z0-9._-]+)\|([a-zA-Z0-9._-]+)\|([a-zA-Z0-9._-]+)\|([a-zA-Z0-9._-]+)\|$/", "$4", $hit['hit_name']);
        $results_html .= "<script lang=\"javascript\">
            ft".$analysis->analysis_id.".addFeature({
               data: ".json_encode($hsp_pos).",
               name: \"".$clean_hit_name."\",
               className: \"blast_match\", //can be used for styling
               color: \"#0F8292\",
               type: \"rect\"
           });
        </script>"; ?>
      </div>
    <?php }

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
    $results_html .= theme_table($table);

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
    $results_html .= theme_pager($pager);
    $results_html .= '<a href="#blast-results-top">back to top</a>';
    $results_html .= '</div>';

    $i++;
  }

  // first add a list at the top of the page that can be formatted as the
  // user desires.  We use the theme_item_list function of Drupal to create
  // the list rather than hard-code the HTML here.  Instructions for how
  // to create the list can be found here:
  // https://api.drupal.org/api/drupal/includes%21theme.inc/function/theme_item_list/7 ?>

  <div class="tripal_feature-data-block-desc tripal-data-block-desc">The following BLAST results are available for this feature:</div> <?php
  print '<a name="blast-results-top"></a>';
  print theme_item_list(array(
    'items' => $list_items,
    'title' => '',
    'type' => 'ul',
    'attributes' => array(),
  ));
  print $results_html;
}
