<?php
/**
 * @file
 * @todo Add file header description
 */
 
/**
 * Parse NCBI BLAST XML results into HTML text that can be used by Drupal
 * for default Drupal indexing of pages.  This function parses a single feature
 * at a time.
 *
 * @param $xml_string
 *   The XML text containing blast results for a given feature
 * @param $db
 *   The database object of the database blasted against. This object should 
 *   contain the database id ($db->db_id) that will be used to lookup 
 *   regular expressions for parsing of blast hits.
 * @param $feature_id 
 *   The feature ID of the feature the XML blast results belong to.
 *
 * @return
 *   Returns HTML formatted text results with non informative words removed, and
 *   more significant words enclosed in HTML tags ranked by the Drupal indexer
 */
function parse_NCBI_Blast_XML_index_version($xml_string, $db, $feature_id) {
  // Get the parser using db_id
  $sql = "SELECT * FROM {tripal_analysis_blast} WHERE db_id = %d";
  $parser = db_fetch_object(db_query($sql, $db->db_id));
  $db_name = $parser->displayname;
  $is_genbank = $parser->genbank_style;
  $regex_hit_id = $parser->regex_hit_id;
  $regex_hit_def = $parser->regex_hit_def;
  $regex_hit_accession = $parser->regex_hit_accession;
  $hit_organism = $parser->hit_organism;

  // set default if regular expressions have not been specified
  if (!$regex_hit_id) {
    $regex_hit_id = '/^(.*?)\s.*$/';
  } 
  else {
    $regex_hit_id = '/' . $regex_hit_id . '/';
  }
  if (!$regex_hit_def) {
    $regex_hit_def = '/^.*?\s(.*)$/';
  } 
  else {
    $regex_hit_def = '/' . $regex_hit_def . '/';
  }
  if (!$regex_hit_accession) {
    $regex_hit_accession = '/^(.*?)\s.*$/';
  } 
  else {
    $regex_hit_accession = '/' . $regex_hit_accession . '/';
  }
  if ($regex_hit_organism) {
    $regex_hit_organism = '/' . $regex_hit_organism . '/';
  }

  $html_out .= "<h3>$db_name</h3>";

  // Load the XML string. This XML should be an extract
  // of the original XML file with only a single iteration.
  // An iteration is essentially all the hits for a single
  // query sequence.
  $xml_output = simplexml_load_string($xml_string);
  $iteration = '';
  // new XML file parser has added the feature name within <Iteration_query-def> tags.
  if ($xml_output->getName() == 'Iteration') {
    foreach ($xml_output->children() as $xml_tag) {
      if ($xml_tag->getName() == 'Iteration_query-def') {
        $html_out .= "Query: $xml_tag<br>";
      } 
      elseif ($xml_tag->getName() == 'Iteration_hits') {
        $iteration = $xml_tag;
      }
    }
    // This is for the file parsed by the old parser
  } 
  else {
    $iteration = $xml_output;
  }

  // now run through the blast hits/hsps of this iteration
  // and generate the rows of the table
  foreach ($iteration->children() as $hits) {
    $best_evalue = 0;
    foreach ($hits->children() as $hit) {
      $best_evalue = 0;
      $element_name = $hit->getName();
      if ($element_name == 'Hit_id') {
        // if parsing "name, acc, desc" from three tags (1/3)
        if ($is_genbank) {
          $hit_name = $hit;
        }
      } 
      elseif ($element_name == 'Hit_def') {
        if ($is_genbank) {
          $description = $hit;
        } 
        else {
          $accession = preg_replace($regex_hit_accession, "$1", $hit);
          $hit_name = preg_replace($regex_hit_id, "$1", $hit);
          $description = preg_replace($regex_hit_def, "$1", $hit);
          if ($regex_hit_organism) {
              $hit_organism = preg_replace($regex_hit_organism, "$1", $hit);
           }
        }
      } 
      elseif ($element_name == 'Hit_accession') {
        // if parsing "name, acc, desc" from three tags (3/3)
        if ($is_genbank) {
          $accession = $hit;
        }
        // now run through each HSP for this hit
      }
    }
    $html_out .= "<p>$hit_name<br>";
    $html_out .= "$accession<br>";
    $html_out .= "<b>$description</b></br>";
    $hsp_html_out = '';
  }
  return $html_out;
}

/*******************************************************************************
 * This function is a wrapper for the tripal_analysis_blast_parseXML function. 
 * If a directory of blast results is provided then this function will 
 * iterate through all call the tripal_analysis_blast_parseXML file individually
 * for each.  Otherwise, if a single XML file is provided it will call the 
 * function for just that
 *
 * @param $analysis_id
 *   The analysis ID that the blast results belong to
 * @param $blastdb
 *   The database blasted against. 
 * @param $blastfile
 *   The directory path containing a collection of blast XML files or a the
 *   path to a single XML file.  If a directory is provided the function will
 *   iterate through all of the files with the extension provided by the 
 *   $blast_ext argument and extract results.
 * @param $no_parsed
 *   The number of hits to keep. 
 * @param $blast_ext
 *   If the $blastfile argument is a directory path rather than a file then
 *   this function willlook for files with the extension provided.
 * @param $query_re
 *   The regular expression that can uniquely identify the query name.
 *   This parameters is required if the feature name is not the
 *   first word in the blast query name. 
 * @param $query_type
 *   The feature type (e.g. 'gene', 'mRNA', 'contig') of the query. It must
 *   be a valid Sequence Ontology term.
 * @param $query_uniquename. 
 *   The regular expression that can uniquely identify the query unique name.
 *   This parameters is required if the feature name is not the
 *   first word in the blast query name. 
 * @param $is_concat
 *   If the blast result file is simply a list of concatenated blast results 
 *   then this value should be set to 1.  
 * @param $search_keywords
 *   The list of fields to extract from the blast results and store as 
 *   a separate entry in the analysisfeatureprop table.  Th list should be
 *   a single string with elements separated with a bar '|'.  The valid values
 *   include:  'blast_match_name', 'blast_match_description', 'blast_match_organism' 
 *   'blast_match_accession', 'blast_database', 'blast_evalue', 'blast_score'
 * @param $job_id
 *   The job id passed in from the jobs management system
 * 
 * @return
 *   returns 1 for success, 0 for failure
 */
function tripal_analysis_blast_parseXMLFile($analysis_id, $blastdb, $blastfile, 
   $no_parsed, $blastfile_ext, $query_re, $query_type, $query_uniquename,
   $is_concat, $search_keywords, $job_id) {

  // Prepare log
  $filename = preg_replace("/.*\/(.*)/", "$1", $blastfile);
  $logfile = file_directory_path() . "/tripal/tripal_analysis_blast/load_$filename.log";
        $logfile = tempnam(sys_get_temp_dir(), "tripal_analysis_blast_import");
  $log = fopen($logfile, 'a'); // append parsing results to log file
   if (!$log) {
     print "ERROR: cannot open log file: $logfile\n";
     exit;
   }

  // If user input a file (e.g. blast.xml)
  if (is_file($blastfile)) {
      tripal_analysis_blast_parseXML($analysis_id, $blastdb, $blastfile, 
         $no_parsed, $blastfile_ext, $query_re, $query_type, $query_uniquename,
         $job_id, 1, $log, $is_concat, $search_keywords);
  } 
   // Otherwise, $blastfile is a directory. Iterate through all xml files in it
   else {
      if (!$blastfile_ext) {
         $blastfile_ext = 'xml';
      }

    $dir_handle = @opendir($blastfile) or die("Unable to open $blastfile");
    $pattern = sql_regcase($blastfile . "/*.$blastfile_ext");
    $total_files = count(glob($pattern));
    print "$total_files file(s) to be parsed.\n";
    
    $interval = intval($total_files * 0.01);
    if ($interval < 1) {
      $interval = 1;
    }
    $no_file = 0;
      
    // Parsing all files in the directory
    while ($file = readdir($dir_handle)) {
      if (preg_match("/^.*\.$blastfile_ext/i", $file)) {

        tripal_analysis_blast_parseXML($analysis_id, $blastdb, "$blastfile/$file", 
          $no_parsed, $blastfile_ext, $query_re, $query_type, $query_uniquename,
          $job_id, 0, $log, $is_concat, $search_keywords);

        // Set job status
        if ($no_file % $interval == 0) {
          $percentage = (int) (($no_file / $total_files) * 100);
          tripal_job_set_progress($job_id, $percentage);
          print $percentage . "%\r";
        }        
      }
      $no_file ++;
    }
  }

  print "Done.\nSuccessful and failed entries have been saved in the log file:\n $logfile\n";
  fwrite($log, "\n");
  fclose($log);
  return;
}
/*******************************************************************************
 * Parse Blast XML Output file into analysisfeatureprop table
 * Parse NCBI BLAST XML results into chunks of XML for each feature and store 
 * them in the analysisfeatureprop table. 
 *
 * @param $analysis_id
 *   The analysis ID that the blast results belong to
 * @param $blastdb
 *   The database blasted against. 
 * @param $blastfile
 *   The directory path containing a collection of blast XML files or a the
 *   path to a single XML file.  If a directory is provided the function will
 *   iterate through all of the files with the extension provided by the 
 *   $blast_ext argument and extract results.
 * @param $no_parsed
 *   The number of hits to keep. 
 * @param $blast_ext
 *   If the $blastfile argument is a directory path rather than a file then
 *   this function willlook for files with the extension provided.
 * @param $query_re
 *   The regular expression that can uniquely identify the query name.
 *   This parameters is required if the feature name is not the
 *   first word in the blast query name. 
 * @param $query_type
 *   The feature type (e.g. 'gene', 'mRNA', 'contig') of the query. It must
 *   be a valid Sequence Ontology term.
 * @param $query_uniquename. 
 *   The regular expression that can uniquely identify the query unique name.
 *   This parameters is required if the feature name is not the
 *   first word in the blast query name. 
 * @param $job_id
 *   The job id passed in from the jobs management system
 * @param $set_progress
 *   Boolean value that indicates if the job progress should be calculated. For
 *   processing of files in a directory this should be zero.  For a single file
 *   it should be 1.
 * @param $log
 *   The log file handle
 * @param $is_concat
 *   If the blast result file is simply a list of concatenated blast results 
 *   then this value should be set to 1.  
 * @param $search_keywords
 *   The list of fields to extract from the blast results and store as 
 *   a separate entry in the analysisfeatureprop table.  Th list should be
 *   a single string with elements separated with a bar '|'.  The valid values
 *   include:  'blast_match_name', 'blast_match_description', 'blast_match_organism' 
 *   'blast_match_accession', 'blast_database', 'blast_evalue', 'blast_score'
 * 
 * @return
 *   no return value
 */
function tripal_analysis_blast_parseXML($analysis_id, $blastdb, $blastfile, 
   $no_parsed, $blastfile_ext, $query_re, $query_type, $query_uniquename,
   $job_id, $set_progress, $log, $is_concat, $search_keywords) {

  // Parsing started
  print "Parsing File:" . $blastfile . " ...\n";
  fwrite($log, date("D M j G:i:s Y") . ". Loading $blastfile\n");
  if ($no_parsed == 'all') {
    print "Parsing all hits...\n";
  } 
  else {
    print "Parsing top $no_parsed hits...\n";
  }
  
  // Get cvterm_id for 'analysis_blast_output_iteration_hits' which is required
  // for inserting into the analysisfeatureprop table
  $columns = array('cvterm_id');
  $values = array(
     'name' => 'analysis_blast_output_iteration_hits',
     'cv_id' => array(
        'name' => 'tripal'
     ),
  );
  $cvterm = tripal_core_chado_select('cvterm', $columns, $values);
  $type_id = $cvterm[0]->cvterm_id;

  // Load the XML file.
  if (!is_readable($blastfile)) {
     exit("Could not open the XML file '$blastfile'.  Check that file exists and that permissions are correct.\n");
  }
   
  // if the file is a set of concatenated files then we want to split it up
  // and run each one individually
  if ($is_concat) {

    // generate a temporary file name
    $temp = tempnam(sys_get_temp_dir(), 'blast_');
    print "Blast XML file is concatenated.  Breaking apart and parsing each individually: $temp\n";
    $out_fh = fopen($temp, "w");

    // run through the lines of the XML file
    $in_fh = fopen($blastfile, "r");
    while (!feof($in_fh)) {
      $line = fgets($in_fh);
      $line = trim($line);
      if (!$line) {
        continue;
      }
      fwrite($out_fh, "$line\n");
      // if the line begins a set of blast output XML then parse the
      // preceeding set.
      if (preg_match("/<\/BlastOutput>/", $line)) {
        // close the temp file
        fclose($out_fh);
        // now parse this new temp file
        tripal_analysis_blast_parseXML($analysis_id, $blastdb, $temp, 
          $no_parsed, $blastfile_ext, $query_re, $query_type, $query_uniquename,
          $job_id, $set_progress, $log, 0);
        // reopen the file for the next set of results
        $out_fh = fopen($temp, "w");
      }
    }
    fclose($in_fh);
    return;
  } 

  $blastoutput = simplexml_load_file($blastfile);
  if (!$blastoutput) {
    exit("Could not read the XML file '$blastfile'.  Check that the XML file is not corrupted.\n");
  }
  $no_iterations = 0;
  foreach ($blastoutput->children() as $tmp) {
    if ($tmp->getName() == 'BlastOutput_iterations') {
      foreach ($tmp->children() as $itr) {
        if ($itr->getName() == 'Iteration') {
          $no_iterations ++;
        }
      }
    }
  }
  //print "$no_iterations results to be processed.\n";
    
  $interval = intval($no_iterations * 0.01);
  if ($interval == 0) {
     $interval = 1;
  }
  $idx_iterations = 0;
  foreach ($blastoutput->children() as $blastoutput_tags) {
print memory_get_usage();
    if ($blastoutput_tags->getName() == 'BlastOutput_iterations') {
      foreach ($blastoutput_tags->children() as $iterations) {
        if ($iterations->getName() == 'Iteration') {

          // Set job status
          $idx_iterations++;
          if ($set_progress and $idx_iterations % $interval == 0) {
            $percentage = (int) (($idx_iterations / $no_iterations) * 100);
            tripal_job_set_progress($job_id, $percentage);
            print $percentage . "%\r";
          }
          
          // now run through the blast hits/hsps of this iteration
          $feature_id = 0;
          foreach ($iterations->children() as $iteration_tags) {

            // Match chado feature uniquename with <Iteration_query-def>
            // and get the feature_id
            $featurenaem_xml = '';
            if ($iteration_tags->getName() == 'Iteration_query-def') {

              // If the Iteration_query-def in the format provided by the 
                     // user's regular expression 
              if ($query_re and preg_match("/$query_re/", $iteration_tags, $matches)) {
                $feature = $matches[1];
              } 
                     // If not in above format then pull up to the first space
                     else {
                if (preg_match('/^(.*?)\s.*$/', $iteration_tags, $matches)) {
                   $feature = $matches[1];
                        } 
                        // if no match up to the first space then just use the entire string
                        else {
                           $feature = $iteration_tags;
                        }
                     }   

                     if (!$feature and $query_re) {
                        print "ERROR: cannot find feature for $iteration_tags using the regular expression: $query_re\n";
                        exit;
                     }

                     // now find the feature in chado
                     $select = array();
                     if ($query_uniquename) {
                        $select['uniquename'] = $feature;
                     } 
                     else {
                        $select['name'] = $feature;
                     }
                     if ($query_type) {
                        $select['type_id'] = array(
                          'cv_id' => array(
                             'name' => 'sequence'
                          ),
                          'name' => $query_type,
                        );
                     }

                     $feature_arr = tripal_core_chado_select('feature', array('feature_id'), $select);
                     if (count($feature_arr) > 1) {
                       fwrite($log, "Ambiguous: '$feature' matches more than one feature and is being skipped.\n");
                       continue;
                     }
                     if (count($feature_arr) == 0) {
                        fwrite($log, "Failed: '$feature' cannot find a matching feature in the database.\n");
                        continue;
                     }
                     $feature_id = $feature_arr[0]->feature_id;

                     fwrite($log, "Matched: '$feature' => feature id:" . $feature_id);
                     $featurename_xml = $iteration_tags->asXML();
                  } 
                  // Insert Iteration_hits into analysisfeatureprop and analysisfeature tables
                  elseif ($iteration_tags->getName() == 'Iteration_hits') {
                     $analysisfeature_id = '';
              if ($feature_id) {
                // Make sure this iteration doesn't exist in analysisfeatureprop. If it does, update but not insert
                $sql = "SELECT analysisfeatureprop_id FROM {analysisfeatureprop} AFP ".
                               "INNER JOIN analysisfeature AF ON AF.analysisfeature_id = AFP.analysisfeature_id ".       
                               "WHERE feature_id=%d ".
                                  "AND analysis_id=%d ".
                                  "AND type_id=%d ";

                $result = db_query($sql, $feature_id, $analysis_id, $type_id);
                $analysisfeatureprop = db_fetch_object($result);
                $xml_content =  "<Iteration>\n" . $featurename_xml . "\n";
                // parse all hits
                if ($no_parsed == 'all') {
                  $xml_content .= $iteration_tags->asXML();
                // parse only top hits
                } 
                else {
                  $counter = 0;
                  $xml_content .= "<Iteration_hits>\n";
                  foreach ($iteration_tags->children() As $hit) {
                    if ($counter < $no_parsed) {
                      $xml_content .= $hit->asXML();
                    } 
                    else {
                      break;
                    }
                    $counter ++;
                  }
                  $xml_content .= "</Iteration_hits>";
                }
                $xml_content .= "\n</Iteration>";

                // If this Iteration_hit already exists then update it
                if ($analysisfeatureprop) {
                   print "Update analysis feature record for feature $feature_id\n";
                  $sql = "UPDATE {analysisfeatureprop} ".
                                  "SET value = '%s' ".
                                  "WHERE analysisfeatureprop_id = %d ";  
                  db_query($sql, $xml_content, $analysisfeatureprop->analysisfeatureprop_id);
                  fwrite($log, " (Update)\n"); // write to log   
                  
                  // Get the analysisfeature_id
                  $sql = "SELECT analysisfeature_id FROM {analysisfeature} ".
                         "WHERE feature_id = %d AND analysis_id = %d";
                  $analysisfeature_id = db_result(db_query($sql, $feature_id, $analysis_id));
                }                        
                 // Otherwise, insert the Iteration_hits into analysisfeature 
                 // and analysisfeatureprop tables
                else {
                      print "Insert analysis feature record for feature $feature_id\n";
                                   
                  // Insert into analysisfeature table
                  $sql = "INSERT INTO {analysisfeature} (feature_id, analysis_id) ".
                                  "VALUES (%d, %d)";
                  db_query($sql, $feature_id, $analysis_id);
                  // Get the newly inserted analysisfeature_id
                  $sql = "SELECT analysisfeature_id FROM {analysisfeature} WHERE feature_id = %d AND analysis_id = %d";
                  $analysisfeature_id = db_result(db_query($sql, $feature_id, $analysis_id));

                  // Insert into analysisfeatureprop table
                  $sql = "INSERT INTO {analysisfeatureprop} (analysisfeature_id, type_id, value, rank)".
                                  "VALUES (%d, %d, '%s', %d)";
                  db_query($sql, $analysisfeature_id, $type_id, $xml_content, '0');
                  fwrite($log, " (Insert)\n"); // write to log
                }
                // if the admin has requested adding of keywords from blast
                // results to the analyisfeatureprop table then do so now.
                if ($search_keywords) {
                   // remove any existing entries. we'll replace them.
                           $match = array('analysisfeature_id' => $analysisfeature_id);
                           tripal_core_chado_delete('blast_hit_data', $match);
                   print "Adding search keywords...\n";

                   // get the db object
                   $columns = array('*');                                     
                   $values = array('db_id' => $blastdb);
                   $db = tripal_core_chado_select('db', $columns, $values);
                   
                   // get the analysis object
                   $values = array('analysis_id' => $analysis_id);
                   $analysis = tripal_core_chado_select('analysis', $columns, $values);
                   
                   // get the blast object
                   $blast_obj = tripal_analysis_blast_get_result_object(
                      $xml_content, $db[0], 0, $feature_id, $analysis[0]);                      
                      
                   // iterate through the hits and add the records to the 
                   // blast_hit_data table
                   $failed = 0;
                    for ($i = 0; $i < $blast_obj->number_hits; $i++) {
                            // add the organism to the blast_organisms table, but
                      // fisrt check to make sure it doesn't already exist
                      $blast_org_name = $blast_obj->hits_array[$i]['hit_organism'];
                      $find_sql = "SELECT * FROM {blast_organisms} WHERE blast_org_name = '%s'";
                      $result = db_fetch_object(db_query($find_sql, $blast_org_name));

                      $blast_org_id = $result->blast_org_id;
                      if (!$blast_org_id) {
                        $sql = "INSERT INTO {blast_organisms} (blast_org_name) VALUES ('%s')";
                        db_query($sql, $blast_org_name);
                        $result = db_fetch_object(db_query($find_sql, $blast_org_name));
                        $blast_org_id = $result->blast_org_id;
                      }
                    
                       $record = new stdClass();
                       $record->analysisfeature_id = $analysisfeature_id;
                       $record->analysis_id = $analysis_id;
                       $record->feature_id = $feature_id;
                       $record->db_id = $blastdb;
                       $record->hit_num = $i;
                       $record->hit_name = $blast_obj->hits_array[$i]['hit_name'];
                       $record->hit_url = $blast_obj->hits_array[$i]['hit_url'];
                       $record->hit_description = $blast_obj->hits_array[$i]['description'];
                       $record->hit_organism = $blast_org_name;
                       $record->blast_org_id = $blast_org_id;
                       $record->hit_accession =$blast_obj->hits_array[$i]['accession'];
                       $record->hit_best_eval = $blast_obj->hits_array[$i]['best_evalue'];
                       $record->hit_best_score = $blast_obj->hits_array[$i]['best_score'];
                       $record->hit_pid =$blast_obj->hits_array[$i]['percent_identity'];
                       if (!drupal_write_record('blast_hit_data', $record)) {
                          $failed =1;
                       }
                    }
                    if ($failed) {
                      fwrite($log, "Failed to add keywords for searching: '$feature'\n");   
                    } 
                    else {
                      fwrite($log, "Added keywords for searching: '$feature'\n");                       
                    }
                }
              }
            }  
          }
        }
      }
    }
  }
  tripal_db_set_active($previous_db); // Use drupal database
}
/*******************************************************************************
 * Parses the BLAST XML for a single iteration (feature matches) and returns
 * an object containing the results. 
 *
 * @param $xml_string
 *   The XML results for a single iteration. The XML should begin with the
 *   <Iteration> tag.
 * @param $db
 *   An instantiated object containing the database record of the database
 *   the features were blasted against.
 * @param $max
 *   The maximum number of matches to retreive from the blast XML results. Set to
 *   zero to retrieve all hits.
 * @param $feature_id
 *   The feature ID to which this blast XML belongs.
 * @param $analysis
 *   An instantiated object containing the analysis record of the blast analysis
 * 
 * @return
 *   An object with the following fields:
 *
 *      Incoming arguments:
 *        $blast_obj->analysis     (the analysis object)
 *        $blast_obj->db           (the database object)
 *        $blast_obj->max          (the maximum number of hits)
 *        $blast_obj->feature_id   (the feature ID of this match)
 *
 *      Meta Information about the results
 *        $blast_obj->xml_tag      (the feature name)
 *        $blast_obj->number_hits  (the number of hits in the array)
 *        $blast_obj->title        (the database name--or analysis if no dbname)
 *
 *      The hits array. The variable $i is indicate an index variable that needs
 *      to be used for accessing the hits.
 *        $blast_obj->hits_array
 *        $blast_obj->hits_array[$i]['arrowr_url']
 *        $blast_obj->hits_array[$i]['accession']
 *        $blast_obj->hits_array[$i]['hit_name']
 *        $blast_obj->hits_array[$i]['hit_url']
 *        $blast_obj->hits_array[$i]['best_evalue']
 *        $blast_obj->hits_array[$i]['percent_identity']
 *        $blast_obj->hits_array[$i]['description']
 *
 *      The HSPs for a given hit. The variable $j indicates an index variable that
 *      needs to be used for accessing the HSPs.
 *        $blast_obj->hits_array[$i]['hsp'][$j]['query_frame']
 *        $blast_obj->hits_array[$i]['hsp'][$j]['hsp_num']
 *        $blast_obj->hits_array[$i]['hsp'][$j]['bit_score']
 *        $blast_obj->hits_array[$i]['hsp'][$j]['score']
 *        $blast_obj->hits_array[$i]['hsp'][$j]['evalue']
 *        $blast_obj->hits_array[$i]['hsp'][$j]['query_frame']
 *        $blast_obj->hits_array[$i]['hsp'][$j]['qseq']
 *        $blast_obj->hits_array[$i]['hsp'][$j]['midline']
 *        $blast_obj->hits_array[$i]['hsp'][$j]['hseq']
 *        $blast_obj->hits_array[$i]['hsp'][$j]['hit_from']
 *        $blast_obj->hits_array[$i]['hsp'][$j]['hit_to']
 *        $blast_obj->hits_array[$i]['hsp'][$j]['identity']
 *        $blast_obj->hits_array[$i]['hsp'][$j]['align_len']
 *        $blast_obj->hits_array[$i]['hsp'][$j]['positive']
 *        $blast_obj->hits_array[$i]['hsp'][$j]['query_from']
 *        $blast_obj->hits_array[$i]['hsp'][$j]['query_to']
 */
function tripal_analysis_blast_get_result_object($xml_string, $db, $max, $feature_id, $analysis) {
  $blast_object = new stdClass();

  // Get the parser using db_id
  $sql = "SELECT * FROM {tripal_analysis_blast} WHERE db_id = %d";
  $parser = db_fetch_object(db_query($sql, $db->db_id));
  $db_name = $parser->displayname;
  $is_genbank = $parser->genbank_style;
  $regex_hit_id = $parser->regex_hit_id;
  $regex_hit_def = $parser->regex_hit_def;
  $regex_hit_organism = $parser->regex_hit_organism;
  $regex_hit_accession = $parser->regex_hit_accession;  
  $hit_organism = $parser->hit_organism;

  // set default if regular expressions have not been specified
  if (!$regex_hit_id) {
    $regex_hit_id = '/^(.*?)\s.*$/';
  } 
  else {
    $regex_hit_id = '/' . $regex_hit_id . '/';
  }
  if (!$regex_hit_def) {
    $regex_hit_def = '/^.*?\s(.*)$/';
  } 
  else {
    $regex_hit_def = '/' . $regex_hit_def . '/';
  }
  if (!$regex_hit_accession) {
    $regex_hit_accession = '/^(.*?)\s.*$/';
  } 
  else {
    $regex_hit_accession = '/' . $regex_hit_accession . '/';
  }
  if ($regex_hit_organism) {
    $regex_hit_organism = '/' . $regex_hit_organism . '/';
  }

  // Get analysis information
  $blast_object->analysis = $analysis;
  $db->displayname = $db_name;
  $blast_object->db = $db;
  if (!$db_name) {
    $blast_object->title = $analysis->name;
  } 
  else {
    $blast_object->title = $db_name;
  }

  // Find node id for the analysis
  $ana_nid = db_result(db_query("SELECT nid FROM {chado_analysis} WHERE analysis_id = %d", $analysis->analysis_id));
   $analysis->nid = $ana_nid;


  // Load the file.  This XML file should be an extract
  // of the original XML file with only a single iteration.
  // An iteration is essentially all the hits for a single
  // query sequence.
  $xml_output = simplexml_load_string($xml_string);
  $iteration = '';
  // new XML file parser has added the feature name within <Iteration_query-def> tags.
  if ($xml_output->getName() == 'Iteration') {
    foreach ($xml_output->children() as $xml_tag) {
      if ($xml_tag->getName() == 'Iteration_query-def') {
        // Here we show the feature name again to check if we pull the correct data
        $blast_object->xml_tag = $xml_tag;
      } 
      elseif ($xml_tag->getName() == 'Iteration_hits') {
        $iteration = $xml_tag;
      }
    }
    // This is for the file parsed by the old parser
  } 
  else {
    $iteration = $xml_output;
  }

  $number_hits = 0;
  foreach ($iteration->children() as $hits) {
    $number_hits ++;
  }

  // add the links for updating blast info using Ajax
  $blast_object->max = $max;
  $blast_object->number_hits = $number_hits;
  $blast_object->feature_id = $feature_id;
  
  $hits_array = array();
  $hit_count = 0;

  foreach ($iteration->children() as $hits) {
    $hsp_array = array();
    $counter = 0;
    foreach ($hits->children() as $hit) {
      $best_evalue = 0;
      $best_identity = 0;
      $best_len = 0;
      $element_name = $hit->getName();
      if ($element_name == 'Hit_id') {
        // if parsing "name, acc, desc" from three tags (1/3)
        if ($is_genbank) {
          $hit_name = $hit;
        }
      } 
      elseif ($element_name == 'Hit_def') {
        if ($is_genbank) {
          $description = $hit;
        } 
        else {
          $accession = preg_replace($regex_hit_accession, "$1", $hit);
          $hit_name = preg_replace($regex_hit_id, "$1", $hit);
          $description = preg_replace($regex_hit_def, "$1", $hit);
          if ($regex_hit_organism) {
             $hit_organism = preg_replace($regex_hit_organism, "$1", $hit);
          }
        }
      } 
      elseif ($element_name == 'Hit_accession') {
        // if parsing "name, acc, desc" from three tags (3/3)
        if ($is_genbank) {
          $accession = $hit;
        }
        // now run through each HSP for this hit
      } 
      elseif ($element_name == 'Hit_hsps') {    
        foreach ($hit->children() as $hsp) {
          foreach ($hsp->children() as $hsp_info) {
            $element_name = $hsp_info->getName();
            if ($element_name == 'Hsp_num') {
              $hsp_num = $hsp_info;
            }
            if ($element_name == 'Hsp_bit-score') {
              $hsp_bit_score = $hsp_info;
            }
            if ($element_name == 'Hsp_score') {
              $hsp_score = $hsp_info;
            }
            if ($element_name == 'Hsp_evalue') {
              $hsp_evalue = $hsp_info;
              // use the first evalue for this set of HSPs
              // as the best evalue. This get's shown as
              // info for the overall match.
              if (!$best_evalue) {
                $best_evalue = $hsp_evalue;
              }
            }
            if ($element_name == 'Hsp_query-from') {
              $hsp_query_from = $hsp_info;
            }
            if ($element_name == 'Hsp_query-to') {
              $hsp_query_to = $hsp_info;
            }
            if ($element_name == 'Hsp_hit-from') {
              $hsp_hit_from = $hsp_info;
            }
            if ($element_name == 'Hsp_hit-to') {
              $hsp_hit_to = $hsp_info;
            }
            if ($element_name == 'Hsp_query-frame') {
              $hsp_query_frame = $hsp_info;
            }
            if ($element_name == 'Hsp_identity') {
              $hsp_identity = $hsp_info;
              // use the first evalue for this set of HSPs
              // as the best evalue. This get's shown as
              // info for the overall match.
              if (!$best_identity) {
                $best_identity = $hsp_identity;
              }
            }
            if ($element_name == 'Hsp_positive') {
              $hsp_positive = $hsp_info;
            }
            if ($element_name == 'Hsp_align-len') {
              $hsp_align_len = $hsp_info;
              // use the first evalue for this set of HSPs
              // as the best evalue. This get's shown as
              // info for the overall match.
              if (!$best_len) {
                $best_len = $hsp_align_len;
              }
            }
            if ($element_name == 'Hsp_qseq') {
              $hsp_qseq = $hsp_info;
            }
            if ($element_name == 'Hsp_hseq') {
              $hsp_hseq = $hsp_info;
            }
            if ($element_name == 'Hsp_midline') {
              $hsp_midline = $hsp_info;
            }
          }

          $hsp_content = array();
          $hsp_content['hsp_num'] = $hsp_num;
          $hsp_content['bit_score'] = $hsp_bit_score;
          $hsp_content['score'] = $hsp_score;
          $hsp_content['evalue'] = $hsp_evalue;
          $hsp_content['query_frame'] = $hsp_query_frame;
          $hsp_content['qseq'] = $hsp_qseq;
          $hsp_content['midline'] = $hsp_midline;
          $hsp_content['hseq'] = $hsp_hseq;
          $hsp_content['hit_from'] = $hsp_hit_from;
          $hsp_content['hit_to'] = $hsp_hit_to;
          $hsp_content['identity'] = $hsp_identity;
          $hsp_content['align_len'] = $hsp_align_len;
          $hsp_content['positive'] = $hsp_positive;
          $hsp_content['query_from'] = $hsp_query_from;
          $hsp_content['query_to'] = $hsp_query_to;
          $hsp_array[$counter] = $hsp_content;
          $counter ++;
        }
      }
    }
      $arrowr_url = url(drupal_get_path('theme', 'tripal') . "/images/arrow_r.png");
    $hits_array[$hit_count]['arrowr_url'] = $arrowr_url;
    $hits_array[$hit_count]['accession'] = $accession;
    $hits_array[$hit_count]['hit_organism'] = $hit_organism;    
    $hits_array[$hit_count]['hit_name'] = $hit_name;
    
    if ($accession && $db->urlprefix) {
      $hits_array[$hit_count]['hit_url'] = "$db->urlprefix$accession";
    } 
    else {
      // Test if this is another feature in the database
      $sql = "SELECT feature_id FROM {feature} WHERE uniquename = '%s'";
      $previous_db = db_set_active('chado');
      $hit_feature_id = db_result(db_query($sql, $hit_name));
      db_set_active($previous_db);
      // If it is, add link to that feature
      if ($hit_feature_id) {
        $hits_array[$hit_count]['hit_url'] = "ID$hit_feature_id";
      }
    }
    
    $hits_array[$hit_count]['best_evalue'] = $best_evalue;
    
    if (!empty($best_len)) {
      $percent_identity = number_format($best_identity/$best_len*100, 2);
      $hits_array[$hit_count]['percent_identity'] = $percent_identity;
    }

    $hits_array[$hit_count]['description'] = $description;
    
    // if there is at least one HSP
    if (!empty($hsp_array[0]['query_frame'])) {
      $hits_array[$hit_count]['hsp'] = $hsp_array;
    } 
    else {
      $hits_array[$hit_count]['hsp'] = array();
    }
    
    $hit_count ++;
    
    // if we've hit the maximum number of hits then return
    if ($max > 0 && $hit_count >= $max) {
      break;
    }
  }
  $blast_object->hits_array = $hits_array;
  return $blast_object;
}

/********************************************************************************
 * Parse the best hit to generate the best hit homology report 
 */
function tripal_analysis_blast_parse_best_hit($analysis_id) {

  // Select all features for this blast analysis, and save them to the 'feature_set' array
  $sql = "SELECT feature_id
          FROM {analysisfeature} AF  
               WHERE analysis_id = %d";
  $previous_db = tripal_db_set_active('chado');
  $result = db_query($sql, $analysis_id);
  $feature_set = array();
  $counter = 0;
  while ($feature = db_fetch_object($result)) {
    $feature_set[$counter] = $feature->feature_id;
    $counter ++;
  }

  // Get analysis information including 'Time', 'Name', and 'DB Settings'
  $sql = "SELECT value, name, to_char(timeexecuted, 'MM-DD-YYYY') AS time
                FROM {analysis}  A
                INNER JOIN {analysisprop} AP ON  A.analysis_id = AP.analysis_id 
                WHERE A.analysis_id = %d
                AND type_id= (SELECT cvterm_id FROM {cvterm} 
                              WHERE name = 'analysis_blast_settings')";
  $analysis = db_fetch_object(db_query($sql, $analysis_id));
  
  // Parse the blast settings
  $blastsettings = explode("|", $analysis->value);
  $db_id = $blastsettings [0];
  
  // Get the xml description parser using db_id
  tripal_db_set_active($previous_db);
  $sql = "SELECT * FROM {tripal_analysis_blast} WHERE db_id = %d";
  $parser = db_fetch_object(db_query($sql, $db_id));

  $db_name = $parser->displayname;
  $is_genbank = $parser->genbank_style;
  $regex_hit_id = $parser->regex_hit_id;
  $regex_hit_def = $parser->regex_hit_def;
  $regex_hit_accession = $parser->regex_hit_accession;
  $regex_hit_organism = $parser->regex_hit_organism;
  $hit_organism = $parser->hit_organism;

  // set default description parser  if regular expressions have not been specified
  if (!$regex_hit_id) {
    $regex_hit_id = '/^(.*?)\s.*$/';
  } 
  else {
    $regex_hit_id = '/' . $regex_hit_id . '/';
  }
  if (!$regex_hit_def) {
    $regex_hit_def = '/^.*?\s(.*)$/';
  } 
  else {
    $regex_hit_def = '/' . $regex_hit_def . '/';
  }
  if (!$regex_hit_accession) {
    $regex_hit_accession = '/^(.*?)\s.*$/';
  } 
  else {
    $regex_hit_accession = '/' . $regex_hit_accession . '/';
  }
  if ($regex_hit_organism) {
    $regex_hit_organism = '/' . $regex_hit_accession . '/';
  }
  
   $interval = intval($counter * 0.01);
  for ($i = 0; $i < $counter; $i ++) {
    
    if ($i !=0 && $i % $interval == 0) {
      $percentage = (int) ($i / $counter * 100);
      tripal_job_set_progress($job_id, $percentage);  
      print $percentage . "%\r";
    }

    $sql = "SELECT value
            FROM {analysisfeatureprop} AFP 
               INNER JOIN {analysisfeature} AF ON AFP.analysisfeature_id = AF.analysisfeature_id 
            WHERE analysis_id = %d 
               AND feature_id = %d
               AND type_id = (SELECT cvterm_id FROM {cvterm}  
                              WHERE name='analysis_blast_output_iteration_hits' 
                                 AND cv_id = (SELECT cv_id FROM cv WHERE name='tripal'))";
    $previous_db = tripal_db_set_active('chado');
    $xml_output = simplexml_load_string(db_result(db_query($sql, $analysis_id, $feature_set[$i])));
    
    $iteration = '';
    // new XML file parser has added the feature name within <Iteration_query-def> tags.
    if ($xml_output->getName() == 'Iteration') {
      $query = "";
      foreach ($xml_output->children() as $xml_tag) {
        if ($xml_tag->getName() == 'Iteration_query-def') {
          // Here we show the feature name again to check if we pull the correct data
          $query = $xml_tag;
        } 
        elseif ($xml_tag->getName() == 'Iteration_hits') {
          $iteration = $xml_tag;
        }
      }
     // This is for the file parsed by the old parser
    } 
    else {
      $iteration = $xml_output;
    }

    $number_hits = 0;
    foreach ($iteration->children() as $hits) {
      $number_hits ++;
    }

    $query = explode(" ", $query) ;
    $query = $query [0];
    if ($number_hits == 0) {
      continue;
    } 

    // now run through the blast hits/hsps of this iteration
    // and generate the rows of the table

    foreach ($iteration->children() as $hits) {
      $hit_count++;
      foreach ($hits->children() as $hit) {
        $best_evalue = 0;
        $best_identity = 0;
        $best_len = 0;
        $element_name = $hit->getName();
        if ($element_name == 'Hit_id') {
          // if parsing "name, acc, desc" from three tags (1/3)
          if ($is_genbank) {
            $hit_name = $hit;
          }
        } 
        elseif ($element_name == 'Hit_def') {
          if ($is_genbank) {
            $description = $hit;
          } 
          else {
            $accession = preg_replace($regex_hit_accession, "$1", $hit);
            $hit_name = preg_replace($regex_hit_id, "$1", $hit);
            $description = preg_replace($regex_hit_def, "$1", $hit);  
            if ($regex_hit_organism) {        
               $hit_organism = preg_replace($regex_hit_organism, "$1", $hit);  
            };          
          }
        } 
        elseif ($element_name == 'Hit_accession') {
          // if parsing "name, acc, desc" from three tags (3/3)
          if ($is_genbank) {
            $accession = $hit;
          }
          // now run through each HSP for this hit
        } 
        elseif ($element_name == 'Hit_hsps') {
          foreach ($hit->children() as $hsp) {
            foreach ($hsp->children() as $hsp_info) {
              $element_name = $hsp_info->getName();
              if ($element_name == 'Hsp_num') {
                $hsp_num = $hsp_info;
              }
              if ($element_name == 'Hsp_bit-score') {
                $hsp_bit_score = $hsp_info;
              }
              if ($element_name == 'Hsp_score') {
                $hsp_score = $hsp_info;
              }
              if ($element_name == 'Hsp_evalue') {
                $hsp_evalue = $hsp_info;
                // use the first evalue for this set of HSPs
                // as the best evalue. This get's shown as
                // info for the overall match.
                if (!$best_evalue) {
                  $best_evalue = $hsp_evalue;
                }
              }
              if ($element_name == 'Hsp_query-from') {
                $hsp_query_from = $hsp_info;
              }
              if ($element_name == 'Hsp_query-to') {
                $hsp_query_to = $hsp_info;
              }
              if ($element_name == 'Hsp_hit-from') {
                $hsp_hit_from = $hsp_info;
              }
              if ($element_name == 'Hsp_hit-to') {
                $hsp_hit_to = $hsp_info;
              }
              if ($element_name == 'Hsp_query-frame') {
                $hsp_query_frame = $hsp_info;
              }
              if ($element_name == 'Hsp_identity') {
                $hsp_identity = $hsp_info;
                // use the first evalue for this set of HSPs
                // as the best evalue. This get's shown as
                // info for the overall match.
                if (!$best_identity) {
                  $best_identity = $hsp_identity;
                }
              }
              if ($element_name == 'Hsp_positive') {
                $hsp_positive = $hsp_info;
              }
              if ($element_name == 'Hsp_align-len') {
                $hsp_align_len = $hsp_info;
                // use the first evalue for this set of HSPs
                // as the best evalue. This get's shown as
                // info for the overall match.
                if (!$best_len) {
                  $best_len = $hsp_align_len;
                }
              }
              if ($element_name == 'Hsp_qseq') {
                $hsp_qseq = $hsp_info;
              }
              if ($element_name == 'Hsp_hseq') {
                $hsp_hseq = $hsp_info;
              }
              if ($element_name == 'Hsp_midline') {
                $hsp_midline = $hsp_info;
              }
            }
          }
        }
      }
      
      // Get analysisfeature_id
      $sql = "SELECT analysisfeature_id FROM {analysisfeature} WHERE analysis_id = %d AND feature_id = %d";
      $af_id = db_result(db_query($sql, $analysis_id, $feature_set[$i]));
      
      // Get type_id
      $sql = "SELECT cvterm_id FROM {cvterm} WHERE name = '%s' AND cv_id = (SELECT cv_id FROM {cv} WHERE name = 'tripal')"; 
      $type_id = db_result(db_query($sql, 'analysis_blast_besthit_query'));
      
      $sql_test = "SELECT analysisfeatureprop_id FROM {analysisfeatureprop} WHERE analysisfeature_id = %d AND type_id = %d";
      $test_afpid = db_result(db_query($sql_test, $af_id, $type_id));
      
      //Insert only if this blast query not exists. 
      if (!$test_afpid) {
        $afp_sql = "INSERT INTO {analysisfeatureprop} (analysisfeature_id, type_id, value, rank) VALUES (%d, %d, '%s', 0)";
        
        //$query;
        db_query($afp_sql, $af_id, $type_id, $query);

        //$hit_name;
        $type_id = db_result(db_query($sql, 'analysis_blast_besthit_match'));
        db_query($afp_sql, $af_id, $type_id, $hit_name);
          
        //$description;
        $type_id = db_result(db_query($sql, 'analysis_blast_besthit_description'));
        db_query($afp_sql, $af_id, $type_id, $description);
          
        //$best_evalue;
        $type_id = db_result(db_query($sql, 'analysis_blast_besthit_evalue'));
        $e_digit = explode("e-", $best_evalue);
        if (count($e_digit) == 2) {
          $evalue_shown = number_format($e_digit [0], 1);
          $best_evalue = $evalue_shown . "e-" . $e_digit[1];
        } 
        db_query($afp_sql, $af_id, $type_id, $best_evalue);
          
        //$best_identity;
        $type_id = db_result(db_query($sql, 'analysis_blast_besthit_identity'));
        $percent_identity = number_format($best_identity/$best_len*100, 1);
        db_query($afp_sql, $af_id, $type_id, $percent_identity);
          
        //$best_len;
        $type_id = db_result(db_query($sql, 'analysis_blast_besthit_length'));
        db_query($afp_sql, $af_id, $type_id, $best_len);
        
      // Otherwise, update all instead
      } 
      else {
        $afp_sql = "UPDATE {analysisfeatureprop} SET analysisfeature_id = %d, type_id = %d, value = '%s', rank = 0 WHERE analysisfeatureprop_id = %d";

        //$query;
        db_query($afp_sql, $af_id, $type_id, $query, $test_afpid);
        
        //$hit_name;
        $type_id = db_result(db_query($sql, 'analysis_blast_besthit_match'));
        $test_afpid = db_result(db_query($sql_test, $type_id));
        db_query($afp_sql, $af_id, $type_id, $hit_name, $test_afpid);

        //$description;
        $type_id = db_result(db_query($sql, 'analysis_blast_besthit_description'));
        $test_afpid = db_result(db_query($sql_test, $type_id));
        db_query($afp_sql, $af_id, $type_id, $description, $test_afpid);
          
        //$best_evalue;
        $type_id = db_result(db_query($sql, 'analysis_blast_besthit_evalue'));
        $test_afpid = db_result(db_query($sql_test, $type_id));
        $e_digit = explode("e-", $best_evalue);
        if (count($e_digit) == 2) {
          $evalue_shown = number_format($e_digit [0], 1);
          $best_evalue = $evalue_shown . "e-" . $e_digit[1];
        } 
        db_query($afp_sql, $af_id, $type_id, $best_evalue, $test_afpid);
          
        //$best_identity;
        $type_id = db_result(db_query($sql, 'analysis_blast_besthit_identity'));
        $test_afpid = db_result(db_query($sql_test, $type_id));
        $percent_identity = number_format($best_identity/$best_len*100, 1);
        db_query($afp_sql, $af_id, $type_id, $percent_identity, $test_afpid);
          
        //$best_len;
        $type_id = db_result(db_query($sql, 'analysis_blast_besthit_length'));
        $test_afpid = db_result(db_query($sql_test, $type_id));
        db_query($afp_sql, $af_id, $type_id, $best_len, $test_afpid);
        
      }
      tripal_db_set_active($previous_db);
      
      break;
    }
  }
   print "100%\n";
  return;
}
