<?php

namespace Drupal\tripal_analysis_blast\Services;

use Symfony\Component\Yaml\Yaml;
use Drupal\tripal_chado\ChadoCustomTables\ChadoCustomTable;
use Drupal\tripal_chado\Database\ChadoConnection;

/**
 * Service for handling tripal_analysis_blast's rebuild logic.
 */
class TripalAnalysisBlastRebuildService {

  /**
   * The chado connection used to query chado.
   *
   * @var Drupal\tripal_chado\Database\ChadoConnection
   */
  protected ChadoConnection $chado_connection;

  /**
   * Constructs a new TripalAnalysisBlastRebuildService object.
   *
   * @param Drupal\tripal_chado\Database\ChadoConnection $chado_connection
   *   The chado connection used to query chado.
   */
  public function __construct(
    ChadoConnection $chado_connection,
  ) {
    $this->chado_connection = $chado_connection;
  }

  /**
   * Executes the rebuild process for tripal_analysis_blast.
   *
   * This is used to implement hook_rebuild, which is run
   * on cache rebuild.
   */
  public function executeRebuild() {
    $this->createCustomChadoTables();
  }

  /**
   * Creates the custom chado tables used by this module.
   *
   * @param string|null $chado_schema_name
   *   Optional. The chado schema where the custom table will live. If no
   *   schema is specified then the default schema is used.
   *
   * @return void
   *   No return value.
   */
  public function createCustomChadoTables(?string $chado_schema_name = NULL): void {
    if (!$chado_schema_name) {
      $chado_schema_name = $this->chado_connection->getSchemaName();
    }

    $config = __DIR__ . '/../../config/other/tripal_analysis_blast.custom_tables.yml';
    $table_schemas = Yaml::parseFile($config);
    foreach ($table_schemas as $table_name => $table_schema) {
      $args = ['format' => 'drupal', 'source' => 'database'];
      $existing_schema = NULL;
      if ($this->chado_connection->schema()->tableExists($table_name)) {
        $existing_schema = $this->chado_connection->schema()->getTableDef($table_name, $args);
      }
      if (!$existing_schema) {
        // Table does not exist, so create it.
        $customTable = new ChadoCustomTable($table_name, $chado_schema_name);
        $force = FALSE;
        $customTable->setTableSchema($table_schema, $force);
        $customTable->setLocked(TRUE);
      }
    }
  }

  /**
   * Deletes the custom chado tables used by this module.
   *
   * This is intended to be called only when this module is uninstalled.
   *
   * @param string|null $chado_schema_name
   *   Optional. The chado schema where the custom tables live. If no
   *   schema is specified then the default schema is used.
   *
   * @return void
   *   No return value.
   */
  public function dropCustomChadoTables(?string $chado_schema_name = NULL): void {
    if (!$chado_schema_name) {
      $chado_schema_name = $this->chado_connection->getSchemaName();
    }
    $config = __DIR__ . '/../../config/other/tripal_analysis_blast.custom_tables.yml';
    $table_schemas = Yaml::parseFile($config);
    // Drop tables in reverse order of how they were created.
    $table_schemas = array_reverse($table_schemas);
    foreach (array_keys($table_schemas) as $table_name) {
      $customTable = new ChadoCustomTable($table_name, $chado_schema_name);
      $existing_schema = $customTable->getTableSchema();
      if ($existing_schema) {
        $customTable->delete();
      }
    }
  }

  /**
   * Migrates tripal 3 terms from tripal CV and DB to local CV and DB.
   *
   * @return string
   *   A report of changes made. Empty string if no changes made.
   */
  public function migrateTripalTerms(): string {
    $report = '';
    $terms = [
      'analysis_blast_settings',
      'analysis_blast_blastdb',
      'analysis_blast_blastfile',
      'analysis_blast_blastparameters',
      'analysis_blast_no_parsed',
      'analysis_blast_query_re',
      'analysis_blast_query_type',
      'analysis_blast_query_uniquename',
      'analysis_blast_blastfile_ext',
      'analysis_blast_is_concat',
      'analysis_blast_search_keywords',
      'blast_match_name',
      'blast_match_description',
      'blast_match_organism',
      'blast_match_accession',
      'blast_database',
      'blast_evalue',
      'blast_score',
      'blast_pid',
      'analysis_blast_output_iteration_hits',
      'analysis_blast_besthit_query',
      'analysis_blast_besthit_match',
      'analysis_blast_besthit_description',
      'analysis_blast_besthit_evalue',
      'analysis_blast_besthit_identity',
      'analysis_blast_besthit_length',
    ];

    // Lookup record IDs of new DB and CV.
    $query = $this->chado_connection->select('1:db', 'db');
    $query->condition('name', 'local', '=');
    $query->fields('db', ['db_id']);
    $local_db_id = $query->execute()->fetchField();
    if (!$local_db_id) {
      $report = 'tripal_analysis_blast: "local" database does not exist, cannot continue.';
      return $report;
    }
    $query = $this->chado_connection->select('1:cv', 'cv');
    $query->condition('name', 'local', '=');
    $query->fields('cv', ['cv_id']);
    $local_cv_id = $query->execute()->fetchField();
    if (!$local_cv_id) {
      $report = 'tripal_analysis_blast: "local" vocabulary does not exist, cannot continue.';
      return $report;
    }

    // Update db_id in the dbxref from tripal to local.
    $query = $this->chado_connection->select('1:cvterm', 'T');
    $query->join('1:dbxref', 'X', '[T].dbxref_id=[X].dbxref_id');
    $query->join('1:db', 'D', '[X].db_id=[X].db_id');
    $query->condition('T.name', $terms, 'IN');
    $query->condition('D.name', 'tripal', '=');
    $query->addField('X', 'dbxref_id', 'dbxref_id');
    $dbxref_ids = $query->execute()->fetchCol();
    if ($dbxref_ids) {
      $report .= 'tripal_analysis_blast: Updating ' . count($dbxref_ids) . ' dbxref records from tripal to local DB.';
      $update = $this->chado_connection->update('1:dbxref');
      $update->condition('dbxref_id', $dbxref_ids, 'IN');
      $update->fields(['db_id' => $local_db_id]);
      try {
        $update->execute();
      }
      catch (\Throwable $e) {
        $report .= ' Exception: ' . $e->getMessage();
        return $report;
      }
    }

    // Update cv_id in the cvterm from tripal to local.
    $query = $this->chado_connection->select('1:cvterm', 'T');
    $query->join('1:cv', 'V', '[T].cv_id=[V].cv_id');
    $query->condition('T.name', $terms, 'IN');
    $query->condition('V.name', 'tripal', '=');
    $query->addField('T', 'cvterm_id', 'cvterm_id');
    $cvterm_ids = $query->execute()->fetchCol();
    if ($cvterm_ids) {
      $report .= ' tripal_analysis_blast: Updating ' . count($cvterm_ids) . ' cvterm records from tripal to local CV.';
      $update = $this->chado_connection->update('1:cvterm');
      $update->condition('cvterm_id', $cvterm_ids, 'IN');
      $update->fields(['cv_id' => $local_cv_id]);
      try {
        $update->execute();
      }
      catch (\Throwable $e) {
        $report .= ' Exception: ' . $e->getMessage();
        return $report;
      }
    }

    return $report;
  }

}
