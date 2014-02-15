<?php
$feature  = $variables['node']->feature;
$blast_results_list = $feature->tripal_analysis_blast->blast_results_list;
 
// specify the number of records to show per database
$num_results_per_page = 10;
$feature_pager_id = 15; // increment by 1 for each database

if(count($blast_results_list) > 0){
  $i = 0;
  foreach ($blast_results_list as $blast_result) {
    $hits_array = $blast_result->hits_array;
    $db = $blast_result->db;
    $analysis = $blast_result->analysis; 
    $pager_id = $feature_pager_id + $i;
    $total_records = count($hits_array);
    
    $current_page_num = pager_default_initialize($total_records, $num_results_per_page, $pager_id);
    $offset = $num_results_per_page * $current_page_num; ?>
    
    <div class="tripal-info-box-desc tripal_analysis_blast-info-box-desc">
      <strong>BLAST of <?php print $feature->name ?> vs. <?php print $db->name?></strong>
      <br>Analysis Date: <?php print preg_replace("/^(\d+-\d+-\d+) .*/","$1", $analysis->timeexecuted) . " (<a href=".url("node/$analysis->nid").">$analysis->name</a>)"?>
      <br>Total hits: <?php print number_format($total_records) ?>
    </div> <?php 
    
    // the $headers array is an array of fields to use as the colum headers.
    // additional documentation can be found here
    // https://api.drupal.org/api/drupal/includes%21theme.inc/function/theme_table/7
    $headers = array('Match Name', 'E-value', 'Identity', 'Description', '');
    
    // the $rows array contains an array of rows where each row is an array
    // of values for each column of the table in that row.  Additional documentation
    // can be found here:
    // https://api.drupal.org/api/drupal/includes%21theme.inc/function/theme_table/7
    $rows = array(); 
        
    for ($j = $offset ; $j < $offset + $num_results_per_page; $j++) {
      $hit = $hits_array[$j]; 
      $hit_name = $hit['hit_name'];
      if (array_key_exists('hit_url', $hit)) { 
        $hit_name = l($hit_name, $hit['hit_url'], array('attributes' => array('target' => '_blank')));
      }
      $rows[] = array(
        $hit_name,
        $hit['best_evalue'],
        array_key_exists('percent_identity', $hit) ? $hit['percent_identity'] : '',
        $hit['description'],
        "<a href=\"javascript:void(0);\"
          id=\"hsp-link-" . $analysis->analysis_id . "-" .  $j ."\"
          class=\"tripal-analysis-blast-info-hsp-link\"
          \">[more]</a>",
      );
      
      // add a div box for each HSP details.  This box will be invisible by default
      // and made visible when the '[more]' link is clicked
      $hsps_array = $hit['hsp']; ?>
      <div class="tripal-analysis-blast-info-hsp-desc" id="hsp-desc-<?php print $analysis->analysis_id ?>-<?php print $j?>"> 
        <strong>BLAST of <?php print $feature->name ?> vs. <?php print $db->name?></strong>
        <br>Match: <?php print $hit_name . " (" . $hit['description'] . ")"; 
        foreach ($hsps_array AS $hsp) { ?>
          <br>HSP <?php  print $hsp['hsp_num'] ?> Score: <?php print $hsp['bit_score'] ?> bits (<?php print $hsp['score'] ?>), Expect = <?php print $hsp['evalue'] ?><br>Identity = <?php print sprintf("%d/%d (%.2f%%)", $hsp['identity'], $hsp['align_len'], $hsp['identity']/$hsp['align_len']*100) ?>, Postives = <?php print sprintf("%d/%d (%.2f%%)", $hsp['positive'], $hsp['align_len'], $hsp['positive']/$hsp['align_len']*100)?>, Query Frame = <?php print $hsp['query_frame']?>
          <pre>Query: <?php print sprintf("%4d", $hsp['query_from'])?> <?php print $hsp['qseq'] ?> <?php print sprintf("%d", $hsp['query_to']); ?><br>            <?php print $hsp['midline'] ?><br>Sbjct: <?php print sprintf("%4d", $hsp['hit_from']) ?> <?php print $hsp['hseq']?> <?php print sprintf("%d",$hsp['hit_to']) ?>
          </pre><?php 
        } ?>
        <div class="hsp-desc-close"><a href="javascript:void(0)" onclick="this.hide()">[Close]</a></div>
      </div> <?php 
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
    print theme_table($table);
    
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
        'block' => 'homology'
      ),
      'quantity' => $num_results_per_page,
    );
    print theme_pager($pager);
    
    $i++;
  }
}