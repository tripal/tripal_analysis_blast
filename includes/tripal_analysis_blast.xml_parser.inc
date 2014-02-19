<?php
/**
 * @file
 * @todo Add file header description
 */

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

  // If user input a file (e.g. blast.xml)
  if (is_file($blastfile)) {
      tripal_analysis_blast_parseXML($analysis_id, $blastdb, $blastfile, 
         $no_parsed, $blastfile_ext, $query_re, $query_type, $query_uniquename,
         $job_id, $is_concat, $search_keywords);
  } 
   // Otherwise, $blastfile is a directory. Iterate through all xml files in it
   else {
      if (!$blastfile_ext) {
         $blastfile_ext = 'xml';
      }

    $dir_handle = @opendir($blastfile) or die("Unable to open $blastfile");
    $pattern = sql_regcase($blastfile . "/*.$blastfile_ext");
    $total_files = count(glob($pattern));
    $no_file = 0;
      
    // Parsing all files in the directory
    while ($file = readdir($dir_handle)) {
      if (preg_match("/^.*\.$blastfile_ext/i", $file)) {
        print "File $no_file of $total_files: $file                       \n";
        tripal_analysis_blast_parseXML($analysis_id, $blastdb, "$blastfile/$file", 
          $no_parsed, $blastfile_ext, $query_re, $query_type, $query_uniquename,
          $job_id, $is_concat, $search_keywords);
        $no_file ++;
      }
    }
  }

  print "\nDone.\n";

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
   $job_id, $is_concat, $search_keywords) {

  // Get cvterm_id for 'analysis_blast_output_iteration_hits' which is required
  // for inserting into the analysisfeatureprop table
  $values = array(
     'name' => 'analysis_blast_output_iteration_hits',
     'cv_id' => array(
        'name' => 'tripal'
     ),
  );
  $options = array('statement_name' => 'sel_cvterm_nacv');
  $cvterm = tripal_core_chado_select('cvterm', array('cvterm_id'), $values, $options);
  if (count($cvterm) ==  0) {
    watchdog('T_blastXML_load', "Cannot find term 'analysis_blast_output_iteration_hits' used for ".
      "storing blast records.  Cannot continue.", array(), WATCHDOG_ERROR);
    exit;
  }
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
    $count = 1;
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
        print "\nQuery number $count\n";
        tripal_analysis_blast_parseXML($analysis_id, $blastdb, $temp, 
          $no_parsed, $blastfile_ext, $query_re, $query_type, $query_uniquename,
          $job_id, 0, $search_keywords);
        // reopen the file for the next set of results
        $out_fh = fopen($temp, "w");
        $count++;
      }
    }
    fclose($in_fh);
    return;
  } 

  $blastoutput = new XMLReader();
  $blastoutput->open($blastfile);

  // get the number of blast iterations elements
  $no_iterations = 0;
  while ($blastoutput->read()) {
    if ($blastoutput->nodeType == XMLReader::ELEMENT) {
      if (strcmp($blastoutput->name, 'Iteration') == 0) {
            $no_iterations ++;
      }
    }
  }
  $blastoutput->close();
  
  tripal_analysis_blast_loader_set_progress(0, $no_iterations, $job_id);
  
  // reopen the file and iterate through the nodes until we get to the BlastOutput_iterations section
  $blastoutput->open($blastfile);
  $num_read = 0;
  while ($blastoutput->read()) {
    if ($blastoutput->nodeType == XMLReader::ELEMENT) {
      if (strcmp($blastoutput->name, 'BlastOutput_iterations') == 0) {
        // iterate through each Iteration (without descending into the subtree)
        // to count the number of iterations in the file
        while($blastoutput->read()){
          if ($blastoutput->nodeType == XMLReader::ELEMENT) {
            // this should be the <Iteration> tag
            tripal_analysis_blast_handle_iteration($blastoutput, $analysis_id, $blastdb, $blastfile, 
              $no_parsed, $blastfile_ext, $query_re, $query_type, $query_uniquename,
              $job_id, $is_concat, $search_keywords, $type_id, $search_keywords);
            $num_read++;
            tripal_analysis_blast_loader_set_progress($num_read);
          } 
        }
      }
    }
  }
}
/*
 * 
 */
function tripal_analysis_blast_handle_iteration($blastoutput, $analysis_id, $blastdb, $blastfile, 
  $no_parsed, $blastfile_ext, $query_re, $query_type, $query_uniquename,
  $job_id, $is_concat, $search_keywords, $type_id) { 
  
  $feature_id = 0;   
  $analysisfeature_id = 0;  
  $iteration_tags_xml = '';
  $num_hits = 1;

  // iterate though the child nodes of the <Iteration> tag
  while ($blastoutput->read()) {
    // if we've hit the closing tag (</Iteration>) then return
    if ($blastoutput->nodeType == XMLReader::END_ELEMENT) {
      if (strcmp($blastoutput->name, 'Iteration') == 0) {
        return;
      }
    }
    // act based on the type of element 
    if ($blastoutput->nodeType == XMLReader::ELEMENT) {
      $tag_name =  $blastoutput->name;
      $blastoutput->read();
      $value = $blastoutput->value;

      switch ($tag_name) {
        
        // if the node is <Iteration_query-def>
        case 'Iteration_query-def':

          $iteration_tags_xml .= "  <$tag_name>$value</$tag_name>\n";

          // If the user provided a query RE to extract the feature name then use that now 
          if ($query_re and preg_match("/$query_re/", $value, $matches)) {
            $feature = $matches[1];
          } 
          // If not a query RE or match then use the default word before a space as the feature name
          elseif (preg_match('/^(.*?)\s.*$/', $value, $matches)) {
            $feature = $matches[1];
          } 
          // if no match up to the first space then just use the entire string
          else {
            $feature = $value;
          }

          if (!$feature and $query_re) {
            watchdog('T_blastXML_load', "Cannot find feature in '$value' using the " . 
                     "regular expression: $query_re", array(), WATCHDOG_ERROR);
            exit;
          }

          // now find the feature in chado
          $select = array();
          $options = array('statement_name' => 'sel_feature_');
          if ($query_uniquename) {
            $select['uniquename'] = $feature;
            $options['statement_name'] .= 'un';
          } 
          else {
            $select['name'] = $feature;
            $options['statement_name'] .= 'na';
          }
          if ($query_type) {
            $select['type_id'] = array(
              'name' => $query_type,
              'cv_id' => array(
                'name' => 'sequence'
              ),
            );
            $options['statement_name'] .= 'ty';
          }

          $feature_arr = tripal_core_chado_select('feature', array('feature_id'), $select, $options);
          if (!$feature_arr) {
            watchdog('T_blastXML_load', "Database query failed when searching for feature '$value'. Check the Drupal log for additional errors.", 
              array(), WATCHDOG_ERROR);
            exit;  
          }
          if (count($feature_arr) > 1) {
            watchdog('T_blastXML_load', "Ambiguous: '$feature' matches more than one " .
                     "feature and is being skipped", array(), WATCHDOG_ERROR);
            continue;
          }
          if (count($feature_arr) == 0) {
            watchdog('T_blastXML_load', "Failed: '$feature' cannot find a matching " ."
                     feature in the database.", array(), WATCHDOG_ERROR);
            continue;
          }
          $feature_id = $feature_arr[0]->feature_id;

          break;
  
        // if the node is <Iteration_hits>
        case 'Iteration_hits':
          if (!$feature_id) {
            watchdog('T_blastXML_load', "Cannot add blast results as feature_id is missing.", array(), 
              WATCHDOG_ERROR);
            continue;
          }                   
          // we are going to store the XML in the analysisfeatureprop table so we need to rebuild it.          
          $xml_content =  "<Iteration>\n" . $iteration_tags_xml . "    <$tag_name>\n";

          // iterate through each of the <Hit> tags, extract the XML and add to our $xml_content
          while ($blastoutput->next()) {

            // if we've reached the node </Iteration> then break out
            // of the while loop.
            if ($blastoutput->nodeType == XMLReader::END_ELEMENT) {              
              if (strcmp($blastoutput->name, 'Iteration_hits') == 0) {
                break;
              }
            }
            // for each hit, just get the contents as a block of XML. We'll
            // store it as XML in the analysisfeatureprop table.
            if ($blastoutput->nodeType == XMLReader::ELEMENT) {
              if (strcmp($blastoutput->name, 'Hit') == 0) {
                // parse only the hits requested to parse
                if ($no_parsed == 'all' or $num_hits <= $no_parsed) {
                  $xml_content .= "        <Hit>"; # don't need ending \n as it's included in readInnerXML()
                  $xml_content .= $blastoutput->readInnerXML();                  
                  $xml_content .= "</Hit>\n";  # don't need spacing before tag as it's included in readInnerXML()
                }
              }
              $num_hits++;
            }
          }
          $xml_content .= "\n  </$tag_name>\n</Iteration>";
         
          // Make sure this iteration doesn't exist in analysisfeatureprop. If it does, update but not insert
          $sql = "SELECT AFP.analysisfeature_id, AFP.analysisfeatureprop_id 
            FROM {analysisfeatureprop} AFP 
              INNER JOIN {analysisfeature} AF ON AF.analysisfeature_id = AFP.analysisfeature_id        
            WHERE feature_id = :feature_id AND analysis_id = :analysis_id AND type_id = :type_id";
          $results = chado_query($sql, array(':feature_id' => $feature_id, ':analysis_id' => $analysis_id, ':type_id' => $type_id));
          $analysisfeatureprop = $results->fetchObject();

          // if the analysis feature already exists then get that record
          $values = array(
            'feature_id' => $feature_id,
            'analysis_id' => $analysis_id,
          );
          $options = array('statement_name' => 'sel_analysisfeature_fean');
          $result = tripal_core_chado_select('analysisfeature', array('analysisfeature_id'), $values, $options);
          if (count($result) == 0) {
             $options = array('statement_name' => 'ins_analysisfeature_fean');
             $result = tripal_core_chado_insert('analysisfeature', $values, $options);
             if (!$result) {
               watchdog('T_blastXML_load', "Cannot add analysis feature record for $feature.", array(), 
                 WATCHDOG_ERROR);
               continue;    
             }
            $analysisfeature_id = $result['analysisfeature_id'];
          }
          else {
            $analysisfeature_id = $result[0]->analysisfeature_id;
          }
          
          // If the analysisfeatureprop record exists then update it
          if ($analysisfeatureprop) {
            $sql = "UPDATE {analysisfeatureprop}
              SET value = :xml_content 
              WHERE analysisfeatureprop_id = :analysisfeatureprop_id";
            $success = chado_query($sql, array(':xml_content' => $xml_content, 'analysisfeatureprop_id' => $analysisfeatureprop->analysisfeatureprop_id));
            if (!$success) {
              watchdog('T_blastXML_load', "Cannot update analysis feature property record for $feature.", array(), 
                WATCHDOG_ERROR);
              continue;
            }
          }  
          // if the analyisfeatureprop record doesn't exist then add it                       
          else {

            // add the analysisfeatureprop record
            $values = array(
              'analysisfeature_id' => $analysisfeature_id,
              'type_id' => $type_id,
              'value' => $xml_content,
              'rank' => 0,              
            );
            $options = array('statement_name' => 'ins_analysisfeatureprop_antyvara');
            $analysisfeatureprop = tripal_core_chado_insert('analysisfeatureprop', $values, $options);
            if (!$analysisfeatureprop) {
              watchdog('T_blastXML_load', "Cannot add analysis feature property record.", array(), 
                WATCHDOG_ERROR);
              continue;   
            }
          }
          // if the user wants to store the keywords then do so
          if ($search_keywords) {

            // remove any existing entries. we'll replace them.
            $match = array('analysisfeature_id' => $analysisfeature_id);
            $options = array('statement_name' => 'del_analysisfeature_id');
            tripal_core_chado_delete('blast_hit_data', $match, $options);

            // get the db object
            $values = array('db_id' => $blastdb);
            $options = array('statement_name' => 'sel_db_id');
            $db = tripal_core_chado_select('db', array('*'), $values, $options);

            // get the analysis object
            $values = array('analysis_id' => $analysis_id);
            $options = array('statement_name' => 'sel_analysis_id');
            $analysis = tripal_core_chado_select('analysis', array('*'), $values, $options);

            // get the blast object
            $blast_obj = tripal_analysis_blast_get_result_object($xml_content, $db[0], $feature_id, $analysis[0]);
                      
            // iterate through the hits and add the records to the 
            // blast_hit_data table
            $failed = 0;
            for ($i = 0; $i < $blast_obj->number_hits; $i++) {
              // add the organism to the blast_organisms table, but
              // first check to make sure it doesn't already exist                
              $blast_org_name = $blast_obj->hits_array[$i]['hit_organism'];
              if ($blast_org_name) {
                $values = array('blast_org_name' => $blast_org_name);
                $options = array('statement_name' => 'sel_blastorgname_na');
                $result = tripal_core_chado_select('blast_organisms', array('*'), $values, $options);
                // if this organism doesn't exist then add it
                if (!$result or count($result) == 0) {
                  $options = array('statement_name' => 'ins_blastorgname_na');
                  $result = tripal_core_chado_insert('blast_organisms', $values, $options);
                  if (!$result) {
                    watchdog('T_blastXML_load', "Failed to add blast organism: '$blast_org_name'", 
                      array(), WATCHDOG_ERROR);   
                    exit;
                  }
                  $blast_org_id = $result['blast_org_id'];
                }
                else {                                  
                  $blast_org_id = $result[0]->blast_org_id;                
                }
              }       

              $values = array(
                'analysisfeature_id' => $analysisfeature_id,
                'analysis_id' => $analysis_id,
                'feature_id' => $feature_id,
                'db_id' => $blastdb,
                'hit_num' => $i + 1,
                'hit_name' => $blast_obj->hits_array[$i]['hit_name'],
                'hit_url' => $blast_obj->hits_array[$i]['hit_url'],
                'hit_description' => $blast_obj->hits_array[$i]['description'],
                'hit_organism' => $blast_org_name,
                'blast_org_id' => $blast_org_id,
                'hit_accession' => $blast_obj->hits_array[$i]['accession'],
                'hit_best_eval' => $blast_obj->hits_array[$i]['best_evalue'],
                'hit_best_score' => $blast_obj->hits_array[$i]['best_score'],
                'hit_pid' => $blast_obj->hits_array[$i]['percent_identity']
              );
              $options = array('statement_name' => 'ins_blasthitdata_all');
              $result = tripal_core_chado_insert('blast_hit_data', $values, $options);
              if (!$result) {
                $failed = 1;
              }
            }
            if ($failed) {
              watchdog('T_blastXML_load', "Failed to add keywords for searching: '$feature'", 
                array(), WATCHDOG_ERROR);   
              exit;
            }                      
          } // end if ($search_keywords) ...
          break; 

        default:
          $iteration_tags_xml .= "  <$tag_name>$value</$tag_name>\n";
          break;
      }
    }
  }    
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
 *        $blast_obj->hits_array[$i]['best_score']
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
function tripal_analysis_blast_get_result_object($xml_string, $db, $feature_id, $analysis) {
  $blast_object = new stdClass();

  // Get the parser using db_id
  $sql = "SELECT * FROM {tripal_analysis_blast} WHERE db_id = :db_id";
  $parser = db_query($sql, array(':db_id' => $db->db_id))->fetchObject();
  $db_name = $parser->displayname;
  $is_genbank = $parser->genbank_style;
  $regex_hit_id = $parser->regex_hit_id;
  $regex_hit_def = $parser->regex_hit_def;
  $regex_hit_organism = $parser->regex_hit_organism;
  $regex_hit_accession = $parser->regex_hit_accession;  
  $db_organism = $parser->hit_organism;

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
  $ana_nid = db_query("SELECT nid FROM {chado_analysis} WHERE analysis_id = :analysis_id", array(':analysis_id' => $analysis->analysis_id))->fetchField();
  $analysis->nid = $ana_nid;


  // Load the file.  This XML file should be an extract
  // of the original XML file with only a single iteration.
  // An iteration is essentially all the hits for a single
  // query sequence.
  $xml = new XMLReader();
  $xml->xml($xml_string);
  $iteration = '';

  // iterate though the child nodes of the <Iteration> tag
  while ($xml->read()) {
    // if we've hit the closing tag (</Iteration>) then return
    if ($xml->nodeType == XMLReader::END_ELEMENT) {
      if (strcmp($xml->name, 'Iteration') == 0) {
        // we're done
        return;
      }
    }

    // if this is the start of an iteration element
    if ($xml->nodeType == XMLReader::ELEMENT) {
      $iteration_element_name =  $xml->name;
      $xml->read();
      $iteration_element_value = $xml->value;

      switch ($iteration_element_name) {
        case 'Iteration_query-def':
          $blast_object->xml_tag = $iteration_element_value;
          break;
        case 'Iteration_hits':
          $blast_object->xml_tag = $iteration_element_value;

          // add the links for updating blast info using Ajax
          $blast_object->feature_id = $feature_id;
          
          // initalize hit variables
          $hits_array = array();
          $hit_count = 0;       
          $number_hits = 0;   
          $accession = '';
          $hit_name = '';
          $description = '';
          $hit_organism = 'Unknown';
          
          // initalize hsp variables
          $hsp_array = array();
          $counter = 0;
          $best_evalue = 0;
          $best_score = 0;
          $best_identity = 0;
          $best_len = 0;
          
          // iterate through hits
          while ($xml->read()) {
            
            // if we're at the begining of the hit then reset variables
            if ($xml->nodeType == XMLReader::ELEMENT) {
              if (strcmp($xml->name, 'Hit') == 0) {
                $hsp_array = array();
                $counter = 0;
                $best_evalue = 0;
                $best_score = 0;
                $best_identity = 0;
                $best_len = 0;   
                $accession = '';
                $hit_name = '';
                $description = '';
                $hit_organism = 'Unknown';
              }
            }
            
            // if we're at the beginning of any other element then collect data
            if ($xml->nodeType == XMLReader::ELEMENT) {
              $hit_element_name =  $xml->name;
              $xml->read();
              $hit_element_value = $xml->value;              
              
              
              switch ($hit_element_name) { 
                case 'Hit_id':
                  // if parsing "name, acc, desc" from three tags (1/3)
                  if ($is_genbank) {
                    $hit_name = $hit_element_value;
                  }
                  break;
                case 'Hit_def':
                  if ($is_genbank) {
                    $description = $hit_element_value;
                    // look for the organism in the defenition
                    $hit_organism = preg_replace('/^.*\[(.*?)\].*$/', "$1", $hit_element_value, 1, $count);
                    if($count == 0){
                      $hit_organism = 'Unknown';
                    }
                  } 
                  else {
                    $accession = preg_replace($regex_hit_accession, "$1", $hit_element_value);
                    $hit_name = preg_replace($regex_hit_id, "$1", $hit_element_value);
                    $description = preg_replace($regex_hit_def, "$1", $hit_element_value);
                    if ($regex_hit_organism) {
                       $hit_organism = preg_replace($regex_hit_organism, "$1", $hit_element_value);
                    }
                    elseif ($db_organism) {
                      $hit_organism = $db_organism;
                    }
                  }
                  break;
                case 'Hit_accession':
                  // if parsing "name, acc, desc" from three tags (3/3)
                  if ($is_genbank) {
                    $accession = $hit_element_value;
                  }
                  break;
                case 'Hit_hsps':
                  // iterate through the HSP children                                     
                  while ($xml->read()) {                                      
                    
                    // if we're at the beginning of a new element collect data
                    if ($xml->nodeType == XMLReader::ELEMENT) {                    
                      $hsp_element_name = $xml->name;
                      $xml->read();
                      $hsp_info = $xml->value;
                      switch ($hsp_element_name) {
                        case 'Hsp_num':
                          $hsp_num = $hsp_info;
                          break;
                        case 'Hsp_bit-score':
                          $hsp_bit_score = $hsp_info;
                          break;
                        case 'Hsp_score':
                          $hsp_score = $hsp_info;
                          // use the first score for this set of HSPs
                          // as the best score. This get's shown as
                          // info for the overall match.
                          if (!$best_score) {
                            $best_score = $hsp_score;
                          }
                          break;
                        case 'Hsp_evalue':
                          $hsp_evalue = $hsp_info;
                          // use the first evalue for this set of HSPs
                          // as the best evalue. This get's shown as
                          // info for the overall match.
                          if (!$best_evalue) {
                            $best_evalue = $hsp_evalue;
                          }
                          break;
                        case 'Hsp_query-from':
                          $hsp_query_from = $hsp_info;
                          break;
                        case 'Hsp_query-to':
                          $hsp_query_to = $hsp_info;
                          break;
                        case 'Hsp_hit-from':
                          $hsp_hit_from = $hsp_info;
                          break;
                        case 'Hsp_hit-to':
                          $hsp_hit_to = $hsp_info;
                          break;
                        case 'Hsp_query-frame':
                          $hsp_query_frame = $hsp_info;
                          break;
                        case 'Hsp_identity':
                          $hsp_identity = $hsp_info;
                          // use the first evalue for this set of HSPs
                          // as the best evalue. This get's shown as
                          // info for the overall match.
                          if (!$best_identity) {
                            $best_identity = $hsp_identity;
                          }
                          break;
                        case 'Hsp_positive':
                          $hsp_positive = $hsp_info;
                          break;
                        case 'Hsp_align-len':
                          $hsp_align_len = $hsp_info;
                          // use the first evalue for this set of HSPs
                          // as the best evalue. This get's shown as
                          // info for the overall match.
                          if (!$best_len) {
                            $best_len = $hsp_align_len;
                          }
                          break;
                        case 'Hsp_qseq':
                          $hsp_qseq = $hsp_info;
                          break;
                        case 'Hsp_hseq':
                          $hsp_hseq = $hsp_info;
                          break;
                        case 'Hsp_midline':
                          $hsp_midline = $hsp_info;
                          break;                        
                      } // end switch ($hsp_element_name) { ...                      
                    } //  end if ($xml->nodeType == XMLReader::ELEMENT) { ...
                    
                    // if we're at the end of the HSP then set the values
                    // that we gather through previous iterations in this while loop.
                    if ($xml->nodeType == XMLReader::END_ELEMENT) {
                      if (strcmp($xml->name, 'Hsp') == 0) {
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
                      
                      // if we're at the end of the <Hit_hsps> element then 
                      // break out of this while loop                   
                      if (strcmp($xml->name, 'Hit_hsps') == 0) {
                        break; 
                      }
                    } // end if ($xml->nodeType == XMLReader::END_ELEMENT) { ...
                  } // end while ($xml->read()) { ...                                                             
                  break;
              } // end switch ($hit_element_name) { ... 
            } // end if ($xml->nodeType == XMLReader::ELEMENT) {
            
            // if we're at the end of the <Hit> then add this hit to the
            // hits_array
            if ($xml->nodeType == XMLReader::END_ELEMENT) {
              if (strcmp($xml->name, 'Hit') == 0) {
                
                $number_hits++; 
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
                  $sql = "SELECT feature_id FROM {feature} WHERE uniquename = :uniquename";
                  $previous_db = db_set_active('chado');
                  $hit_feature_id = chado_query($sql, array(':uniquename' => $hit_name))->fetchField();
                  db_set_active($previous_db);
                  // If it is, add link to that feature
                  if ($hit_feature_id) {
                    $hits_array[$hit_count]['hit_url'] = "ID$hit_feature_id";
                  }
                }
                
                $hits_array[$hit_count]['best_evalue'] = $best_evalue;
                $hits_array[$hit_count]['best_score'] = $best_score;
                
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
              } // end if (strcmp($xml->name, 'Hit') == 0) { ...
            }  // end if ($xml->nodeType == XMLReader::END_ELEMENT) ...          
          } // end while ($xml->read()) { ...
          break; // end case 'Iteration_hits': ...
      } // end switch ($tag_name) { ...
    } // end if ($xml->nodeType == XMLReader::ELEMENT) { ...
  } // end while ($xml->read()) { ...
  
  $blast_object->number_hits = $number_hits;  
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
               WHERE analysis_id = :analysis_id";
  $result = chado_query($sql, array(':analysis_id' => $analysis_id));
  $feature_set = array();
  $counter = 0;
  while ($feature = $result->fetchObject()) {
    $feature_set[$counter] = $feature->feature_id;
    $counter ++;
  }

  // Get analysis information including 'Time', 'Name', and 'DB Settings'
  $sql = "SELECT value, name, to_char(timeexecuted, 'MM-DD-YYYY') AS time
                FROM {analysis}  A
                INNER JOIN {analysisprop} AP ON  A.analysis_id = AP.analysis_id 
                WHERE A.analysis_id = :analysis_id
                AND type_id= (SELECT cvterm_id FROM {cvterm} 
                              WHERE name = 'analysis_blast_settings')";
  $analysis = chado_query($sql, array(':analysis_id' => $analysis_id))->fetchObject();
  
  // Parse the blast settings
  $blastsettings = explode("|", $analysis->value);
  $db_id = $blastsettings [0];
  
  // Get the xml description parser using db_id
  $sql = "SELECT * FROM {tripal_analysis_blast} WHERE db_id = :db_id";
  $parser = chado_query($sql, array(':db_id' => $db_id))->fetchObject();

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
  if (!$hit_organism) {
    $hit_organism = 'Unknown';    
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
            WHERE analysis_id = :analysis_id 
               AND feature_id = :feature_id
               AND type_id = (SELECT cvterm_id FROM {cvterm}  
                              WHERE name='analysis_blast_output_iteration_hits' 
                                 AND cv_id = (SELECT cv_id FROM cv WHERE name='tripal'))";    
    $xml_output = simplexml_load_string(chado_query($sql, array(':analysis_id' => $analysis_id, ':feature_id' => $feature_set[$i]))->fetchField());
    
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
        $best_score = 0;
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
                // use the first evalue for this set of HSPs
                // as the best evalue. This get's shown as
                // info for the overall match.
                if (!$best_score) {
                  $best_score = $hsp_score;
                }
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
      $sql = "SELECT analysisfeature_id FROM {analysisfeature} WHERE analysis_id = :analysis_id AND feature_id = :feature_id";
      $af_id = chado_query($sql, array(':analysis_id' => $analysis_id, ':feature_id' => $feature_set[$i]))->fetchField();
      
      // Get type_id
      $sql = "SELECT cvterm_id FROM {cvterm} WHERE name = :name AND cv_id = (SELECT cv_id FROM {cv} WHERE name = 'tripal')"; 
      $type_id = chado_query($sql, array(':name' => 'analysis_blast_besthit_query'))->fetchField();
      
      $sql_test = "SELECT analysisfeatureprop_id FROM {analysisfeatureprop} WHERE analysisfeature_id = :analysisfeature_id AND type_id = :type_id";
      $test_afpid = chado_query($sql_test, array('analysisfeature_id:' => $af_id, ':type_id' => $type_id))->fetchField();
      
      //Insert only if this blast query not exists. 
      if (!$test_afpid) {
        $afp_sql = "INSERT INTO {analysisfeatureprop} (analysisfeature_id, type_id, value, rank) VALUES (:analysisfeature_id, :type_id, :rank, 0)";
        
        //$query;
        chado_query($afp_sql, array(':analysisfeature_id' => $af_id, ':type_id' => $type_id, ':rank' => $query));

        //$hit_name;
        $type_id = chado_query($sql, 'analysis_blast_besthit_match')->fetchField();
        chado_query($afp_sql, array(':analysisfeature_id' => $af_id, ':type_id' => $type_id, 'rank' => $hit_name));
          
        //$description;
        $type_id = chado_query($sql, array(':name' => 'analysis_blast_besthit_description'))->fetchField();
        chado_query($afp_sql, array(':analysisfeature_id' => $af_id, ':type_id' => $type_id, ':rank' => $description));
          
        //$best_evalue;
        $type_id = chado_query($sql, array(':name' => 'analysis_blast_besthit_evalue'))->fetchField();
        $e_digit = explode("e-", $best_evalue);
        if (count($e_digit) == 2) {
          $evalue_shown = number_format($e_digit [0], 1);
          $best_evalue = $evalue_shown . "e-" . $e_digit[1];
        } 
        chado_query($afp_sql, array(':analysisfeature_id' => $af_id, ':type_id' => $type_id, ':rank' => $best_evalue));
          
        //$best_identity;
        $type_id = chado_query($sql, array(':name' => 'analysis_blast_besthit_identity'))->fetchField();
        $percent_identity = number_format($best_identity/$best_len*100, 1);
        chado_query($afp_sql, array(':analysisfeature_id' => $af_id, ':type_id' => $type_id, ':rank' => $percent_identity));
          
        //$best_len;
        $type_id = chado_query($sql, array(':name' => 'analysis_blast_besthit_length'))->fetchField();
        chado_query($afp_sql, array(':analysisfeature_id' => $af_id, ':type_id' => $type_id, ':rank' => $best_len));
        
      // Otherwise, update all instead
      } 
      else {
        $afp_sql = "UPDATE {analysisfeatureprop} SET analysisfeature_id = :analysisfeature_id, type_id = :type_id, value = :value, rank = 0 WHERE analysisfeatureprop_id = :analysisfeatureprop_id";

        //$query;
        chado_query($afp_sql, array(':analysisfeature_id' => $af_id, ':type_id' => $type_id, ':value' => $query, ':analysisfeatureprop_id' => $test_afpid));
        
        //$hit_name;
        $type_id = chado_query($sql, array(':name' => 'analysis_blast_besthit_match'))->fetchField();
        $test_afpid = chado_query($sql_test, array(':analysisfeature_id' => $af_id, ':type_id' =>$type_id))->fetchField();
        chado_query($afp_sql, array(':analysisfeature_id' => $af_id, ':type_id' => $type_id, ':value' => $hit_name, ':analysisfeatureprop_id' => $test_afpid));

        //$description;
        $type_id = chado_query($sql, array(':name' => 'analysis_blast_besthit_description'))->fetchField();
        $test_afpid = chado_query($sql_test, array(':analysisfeature_id' => $af_id, ':type_id' =>$type_id))->fetchField();
        chado_query($afp_sql, array(':analysisfeature_id' => $af_id, ':type_id' => $type_id, ':value' => $description, ':analysisfeatureprop_id' => $test_afpid));
          
        //$best_evalue;
        $type_id = chado_query($sql, array(':name' => 'analysis_blast_besthit_evalue'))->fetchField();
        $test_afpid = chado_query($sql_test, array(':analysisfeature_id' => $af_id, ':type_id' =>$type_id))->fetchField();
        $e_digit = explode("e-", $best_evalue);
        if (count($e_digit) == 2) {
          $evalue_shown = number_format($e_digit [0], 1);
          $best_evalue = $evalue_shown . "e-" . $e_digit[1];
        }
        chado_query($afp_sql, array(':analysisfeature_id' => $af_id, ':type_id' => $type_id, ':value' => $best_evalue, ':analysisfeatureprop_id' => $test_afpid));
          
        //$best_identity;
        $type_id = chado_query($sql, array(':name' => 'analysis_blast_besthit_identity'))->fetchField();
        $test_afpid = chado_query($sql_test, array(':analysisfeature_id' => $af_id, ':type_id' =>$type_id))->fetchField();
        $percent_identity = number_format($best_identity/$best_len*100, 1);
        chado_query($afp_sql, array(':analysisfeature_id' => $af_id, ':type_id' => $type_id, ':value' => $percent_identity, ':analysisfeatureprop_id' => $test_afpid));
          
        //$best_len;
        $type_id = chado_query($sql, array(':name' => 'analysis_blast_besthit_length'))->fetchField();
        $test_afpid = chado_query($sql_test, array(':analysisfeature_id' => $af_id, ':type_id' =>$type_id))->fetchField();
        chado_query($afp_sql, array(':analysisfeature_id' => $af_id, ':type_id' => $type_id, ':value' => $best_len, ':analysisfeatureprop_id' => $test_afpid));
        
      }
      
      break;
    }
  }
  print "100%\n";
  return;
}

/*
 *
 */
function tripal_analysis_blast_loader_set_progress($nodes_read, $total = -1, $job = -1) {
  
  static $total_nodes;
  static $interval;
  static $job_id;
  static $intv_read;
  static $last_read;

  if ($total != -1) {
    $total_nodes = $total;
    $interval = intval($total_nodes * 0.001);
    if ($interval == 0) {
      $interval = 1;
    }
    $job_id = $job;
    $intv_read = 0;
    $last_read = 0;
  }  
  $intv_read += $nodes_read - $last_read;
  if ($total_nodes == 0) {
    print "Parsing element " . number_format($nodes_read) . " of " . 
      number_format($total_nodes) . " (100%). Memory: " . 
      number_format(memory_get_usage()) . " bytes.\r";
  }  
  elseif ($intv_read >= $interval) {    
    $intv_read = 0;
    $percent = sprintf("%.2f", ($nodes_read / $total_nodes) * 100);
    print "Parsing element " . number_format($nodes_read) . " of " . 
      number_format($total_nodes) . " (" . $percent . "%). Memory: " . 
      number_format(memory_get_usage()) . " bytes.\r";
    $value = intval(($nodes_read / $total_nodes) * 100);
    tripal_job_set_progress($job_id, $value);
  }  
  $last_read = $nodes_read;
}
