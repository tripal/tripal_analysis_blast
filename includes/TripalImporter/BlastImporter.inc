<?php

class BlastImporter extends TripalImporter {

 /**
   * The name of this loader.  This name will be presented to the site
   * user.
   */
  public static $name = 'Chado BLAST XML results loader';

  /**
   * The machine name for this loader. This name will be used to construct
   * the URL for the loader.
   */
  public static $machine_name = 'blast_loader';

  /**
   * A brief description for this loader.  This description will be
   * presented to the site user.
   */
  public static $description = 'Import a BLAST XML file into Chado';

  /**
   * An array containing the extensions of allowed file types.
   */
  public static $file_types = array('xml');


  /**
   * Provides information to the user about the file upload.  Typically this
   * may include a description of the file types allowed.
   */
  public static $upload_description = 'Please provide the XML file.';

  /**
   * The title that should appear above the upload button.
   */
  public static $upload_title = 'XML File';
  /**
   * @see TripalImporter::form()
   */
  public function form($form, &$form_state) {
    // Default values can come in the following ways:
    $blast = NULL;
    $blastdb = '';
    $blastfile = '';
    $blastfile_ext = '';
    $query_re = '';
    $query_type = '';
    $query_uniquename = '';
    $is_concat = '';
    $search_keywords = '';
    $blastjob = FALSE;

    // if we are re constructing the form from a failed validation or ajax callback
    // then use the $form_state['values'] values
    if (array_key_exists('values', $form_state)) {
        $blastdb          = $form_state['values']['blastdb'];
        $blastfile        = $form_state['values']['blastfile'];
        $blastfile_ext    = $form_state['values']['blastfile_ext'];
        $query_re         = $form_state['values']['query_re'];
        $query_type       = $form_state['values']['query_type'];
        $query_uniquename = $form_state['values']['query_uniquename'];
        $is_concat        = $form_state['values']['is_concat'];
    }
    // if we are re building the form from after submission (from ajax call) then
    // the values are in the $form_state['input'] array
    if (array_key_exists('input', $form_state) and !empty($form_state['input'])) {
        $blastdb          = $form_state['input']['blastdb'];
        $blastfile        = $form_state['input']['blastfile'];
        $blastfile_ext    = $form_state['input']['blastfile_ext'];
        $query_re         = $form_state['input']['query_re'];
        $query_type       = $form_state['input']['query_type'];
        $query_uniquename = isset($form_state['input']['query_uniquename']) ? $form_state['input']['query_uniquename'] : 0;
        $is_concat        = isset($form_state['input']['is_concat']) ? $form_state['input']['is_concat'] : 0;
    }

    // Get the bundle for an analysis
    $analysis_term = tripal_load_term_entity(array('vocabulary' => 'local', 'accession' => 'analysis'));
    $analysis_bundle = tripal_load_bundle_entity(array('term_id' => $analysis_term->id));

    // get the list of analyses
    $sql = "SELECT * FROM {analysis} ORDER BY name";
    $org_rset = chado_query($sql);
    $analyses = array();
    $analyses[''] = '';
    while ($analysis = $org_rset->fetchObject()) {
        $analyses[$analysis->analysis_id] = "$analysis->name ($analysis->program $analysis->programversion, $analysis->sourcename)";
    }
    $form['analysis_id'] = array(
        '#title'       => t('Analysis'),
        '#type'        => t('select'),
        '#description' => t("Choose the record that describes the BLAST analysis that was performed.
        If a BLAST analysis record does not yet exist, !create_analysis. Why specify an analysis for a data load?  All data comes
        from some place, even if downloaded from Genbank. By specifying
        analysis details for all data imports it allows an end user to reproduce the
        data set, but at least indicates the source of the data.", array(
            '!create_analysis' => l('please create one', 'bio_data/add/' . $analysis_bundle->id)
        )),
        '#required'    => TRUE,
        '#options'     => $analyses,
    );

    // get a list of db from chado for user to choose
    $sql = 'SELECT db_id,  name FROM {db} ORDER BY lower(name)';
    $results = chado_query($sql);

    $blastdbs = array();
    while ($db = $results->fetchObject()) {
        $blastdbs[$db->db_id] = $db->name;
    }
    $form['db_options'] = array(
        '#type' => 'value',
        '#value' => $blastdbs
    );
    $form['blastdb'] = array(
        '#title' => t('Database'),
        '#type' => 'select',
        '#description' => t('The database used for the blast analysis. If the database does not appear in this list, please !add_database.
        Each database may have a different format for each match. This blast module will attempt to extract the match name,
        match accession, and organism from each match.  To ensure the parser is able to properly extract
        this information. Please set the proper regular expression values on the !settings_page
        Databases from NCBI have a built-in parser. On the Blast Settings page, simply click the box "Use Genebank style parser"',
            array(
            '!add_database' => l('add a new database', 'admin/tripal/storage/chado/db/add', array('attributes' => array('target' => '_blank'))),
            '!settings_page' => l('Blast Settings page.', 'admin/tripal/extension/tripal_blast_analysis', array('attributes' => array('target' => '_blank')))
            )),
        '#options' => $form['db_options']['#value'],
        '#default_value' => $blastdb,
    );

    $form['blastfile_ext'] = array(
        '#title' => t('Blast XML file extension'),
        '#type' => 'textfield',
        '#description' => t('If a directory is provide for the blast file setting above,  then a file extension can be provided here. Files with this extension in the directory will be parsed.  If no extension is provided then files with a .xml extension will be parsed within the directory. Please provide the extension without the preceeding period (e.g. "out" rather than ".out"'),
        '#default_value' => $blastfile_ext,
    );
    $form['is_concat'] = array(
        '#title' => t('Is the XML file concatenated?'),
        '#type' => 'checkbox',
        '#description' => t('Is the XML file a set of concatenated XML results?  Such is the case,  for instance, if <a href="http://www.blast2go.org/">Blast2GO</a> was used to generate the blast results. If
        NCBI BLAST was used with output in XML then this options should not be checked.'),
        '#default_value' => $is_concat,
    );
    $form['no_parsed'] = array(
        '#title' => t('Number of hits to be parsed'),
        '#type' => 'textfield',
        '#description' => t("The number of hits to be parsed. Tripal will parse only top 10 hits if you input '10'' in this field. Enter the text 'all' to parse all hits. Default is to parse only the top 25 hits per match."),
        '#default_value' => '25',
    );

    $form['query_re'] = array(
        '#title' => t('Query Name RE'),
        '#type' => 'textfield',
        '#description' => t('Enter the regular expression that will extract the '.
            'feature name from the query line in the blast results. This should be '.
            'the same as the definition line in the query FASTA file.  This option is '.
            'is only required when the query does not identically match a feature '.
            'in the database.'),
        '#default_value' => $query_re,
    );

    $cv = tripal_get_cv(array('name' => 'sequence'));
    $cv_id = $cv->cv_id;
    $form['query_type'] = array(
        '#title' => t('Query Type'),
        '#type' => 'textfield',
        '#description' => t('Please enter the Sequence Ontology term that describes '.
            'the query sequences used for blasting.  This is only necessary if two '.
            'or more sequences have the same name.'),
        '#default_value' => $query_type,
        '#autocomplete_path' => "admin/tripal/storage/chado/auto_name/cvterm/$cv_id",
    );

    $form['query_uniquename'] = array(
        '#title' => t('Use Unique Name'),
        '#type' => 'checkbox',
        '#description' => t('Select this checboxk if the query name in the blast file '.
            'matches the uniquename of the feature.  By default,  the blast results will '.
            'mapped to the "name" of the feature.'),
        '#default_value' => $query_uniquename,
    );

    return $form;
  }
  /**
   * @see TripalImporter::formSubmit()
   */
  public function formSubmit($form, &$form_state) {
    global $user;



    return '';
  }
  /**
   * @see TripalImporter::formValidate()
   */
  public function formValidate($form, &$form_state) {
    // trim character fields
    $analysis_id = trim($form_state['values']['analysis_id']);
    $db_options = $form_state['values']['db_options'];
    $blastdb = $form_state['values']['blastdb'];
    $blastfile_ext = trim($form_state['values']['blastfile_ext']);
    $is_concat = $form_state['values']['is_concat'];
    $no_parsed = $form_state['values']['no_parsed'];
    $query_re = trim($form_state['values']['query_re']);
    $query_type   = trim($form_state['values']['query_type']);
    $query_uniquename   = $form_state['values']['query_uniquename'];
  }

  /**
   * @see TripalImporter::run()
   */
  public function run($details, $job_id = NULL) {
    $arguments = $details->arguments;
    $analysis_id = $arguments['analysis_id'];
    $db_options = $arguments['db_options'];
    $blastdb = $arguments['blastdb'];
    $blastfile = trim($arguments['file_path']);
    $blastfile_ext = trim($arguments['blastfile_ext']);
    $is_concat = $arguments['is_concat'];
    $no_parsed = $arguments['no_parsed'];
    $query_re = trim($arguments['query_re']);
    $query_type = trim($arguments['query_type']);
    $query_uniquename = $arguments['query_uniquename'];
    $blastjob = 1;
    $search_keywords = 1;

    tripal_analysis_blast_parseXMLFile($analysis_id, $blastdb, $blastfile,
      $no_parsed, $blastfile_ext, $query_re, $query_type, $query_uniquename,
      $is_concat, $search_keywords);
  }

}
