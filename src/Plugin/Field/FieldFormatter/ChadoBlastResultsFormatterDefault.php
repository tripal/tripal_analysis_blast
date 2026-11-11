<?php

namespace Drupal\tripal_analysis_blast\Plugin\Field\FieldFormatter;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Link;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\tripal\TripalField\Attribute\TripalFieldFormatter;
use Drupal\tripal_chado\TripalField\ChadoFormatterBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'format_blast_results_formatter' field formatter.
 */
#[TripalFieldFormatter(
  id: 'chado_blast_results_formatter_default',
  label: new TranslatableMarkup('Format Blast Results Formatter'),
  description: new TranslatableMarkup('Displays BLAST results from an analysis'),
  field_types: [
    'chado_blast_results',
  ],
)]
class ChadoBlastResultsFormatterDefault extends ChadoFormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    $settings = parent::defaultSettings();
    $settings['todo'] = '@todo';
    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $pager_manager = \Drupal::service('pager.manager');

    $elements = parent::viewElements($items, $langcode);

    // Attaches the javascrip for visualization as defined in
    // tripal_analysis_blast/tripal_analysis_blast.libraries.yml.
    $elements['#attached']['library'][] = 'tripal_analysis_blast/blast-hit-feature-viewer';
    $elements['#attached']['library'][] = 'tripal_analysis_blast/blast-hit-popup';

    // Retrieve display settings. Not used yet.
    $settings = $this->getSetting('todo');

    $num_results_per_page = 10;
    $feature_pager_id = 15; // increment by 1 for each database.
    $pager_index = 0;
    $list_items = [];

    $current_page_num = 0;
    $offset = $num_results_per_page * $current_page_num;

    foreach ($items as $delta => $item) {

      // Retrieve the field data.
      $blast_results = Json::decode($item->get('hit_data')->getString());

      // Each result here would be a query to a particular BLAST database.
      $br_index = 0;
      foreach ($blast_results as $blast_result) {

        // Adds the placeholder for the feature viewer visualization.
        $elements[$delta]['feature_viewer'] = [
          '#markup' => '<div class="feature-viewer-container" id="blast-hit-container-' . $delta . '-' . $br_index . '"></div>',
        ];

        $hits_array = $blast_result['hits_array'];
        $db = $blast_result['db'];
        $analysis = $blast_result['analysis'];
        $pager_id = $feature_pager_id + $pager_index;
        $total_records = count($hits_array ?? []);

        // Add this blast report to the list of available reports.
        // If the linked analysis is published, make this clickable.
        $result_name = 'BLAST of ' . $blast_result['feature_name'] . ' vs. ' . $db['name'];
        if ($analysis['entity_id']) {
          $analysis_url = 'base://bio_data/' . $analysis['entity_id'];
          $result_name = Link::fromTextAndUrl($result_name, Url::fromUri($analysis_url, []))->toString();
        }

        // Set up a pager.
        $pager = $pager_manager->createPager($total_records, $num_results_per_page);

        // Build the table of hits for the current page.
        $header = ['Match Name', 'Expect', 'Identity', 'Description', ''];
        $rows = [];
        for ($hit_index = $offset; $hit_index < $offset + $num_results_per_page && $hit_index < $total_records; $hit_index++) {
          $row_id = 'hsp-link-' . $analysis['analysis_id'] . '-' . $hit_index;
          $hit = $hits_array[$hit_index];

          // Values that are used for all blast results.
          if ($hit_index == $offset) {
            $elements['#attached']['drupalSettings']['tripal_analysis_blast']['query_data']['residues'] = $blast_result['feature_residues'];
          }

          // The hit name for table display, is a link if possible.
          $hit_name = $this->makeHitLinkOut($hit);

          // Provide a shortened description for table display.
          $full_description = $hit['description'] ?: $this->t('No description');
if ($hit_index == 2) { $full_description = 'Test of long description yes this is very long and not containing any information whatsoever'; } //;;;
          $short_description = substr($full_description, 0, 50);
          if (strlen($full_description) > 50) {
            $short_description .= "…";
          }
          // We want a tooltip if the user mouses-over an abbreviated
          // description to show the entire thing.
          $description_element = [
            'data' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => $short_description,
              '#attributes' => ['title' => $full_description],
            ],
          ];
          $evalue = sprintf("%.3e", $hit['best_evalue']);
          $pid = $hit['percent_identity'] ?? '';

          // The details link is a button that will trigger javascript
          // to display a popup with more information.
          $details_link = [
            'data' => [
              '#type' => 'html_tag',
              '#tag' => 'button',
              '#value' => $this->t('details'),
              '#attributes' => [
                'type' => 'button',
                'class' => ['tripal-analysis-blast-popup-trigger'],
                'data-popup-message' => $this->buildPopupData($blast_result, $hit),
                'id' => $row_id,
              ],
              '#attached' => [
                'library' => [
                  'tripal_analysis_blast/blast-hit-popup',
                ],
              ],
            ],
          ];

          // Add the variables used by the javascript.
          $elements['#attached']['drupalSettings']['tripal_analysis_blast']['hit_data'][$hit_index]
            = $this->buildJavascriptData($blast_result, $hit);

          // Add elements for this row to the table.
          $rows[] = [
            $hit_name,
            $evalue,
            $pid,
            $description_element,
            $details_link,
          ];
        }

        // Construct the render array for the hit table.
        $hit_table = [
          '#type' => 'table',
          '#header' => $header,
          '#rows' => $rows,
          '#attributes' => [
            'class' => ['tripal_analysis_blast-results-table'],
          ],
          '#empty' => $this->t('There are no BLAST results for this feature.'),
        ];
        $elements[$delta]['hit_table'] = $hit_table;

        // Increment the blast result index.
        $br_index++;
      }
    }

    return $elements;
  }

  /**
   * Gets the name of the hit and links to external DB if possible.
   *
   * @param array $hit
   *   One blast hit.
   *
   * @return string|Link
   *   Hit name as string, or Drupal\Core\Link if linkout is possible.
   */
  protected function makeHitLinkOut(array $hit): string|Link {
    // @todo use accession in preference? $hit_name = $hit['hit_name'] ?: $hit['accession'] ?: $this->t('no hit identifier');
    $hit_name = $hit['accession'] ?: $hit['hit_name'] ?: $hit['description'] ?: $this->t('no hit identifier');
    $hit_accession = $hit['accession'] ?? NULL;
    $hit_url = $hit['hit_url'] ?? NULL;
    // @todo db not yet implemented in hit array.
    $hit_db = $hit['db'] ?? NULL;
    if ($hit_url) {
      if ($hit_db) {
        $hit_url = preg_replace('/{db}/', $hit_db, $hit_url);
      }
      // If accession placeholder, substitute, otherwise append.
      if ($hit_accession) {
        $hit_url = preg_replace('/{accession}/', $hit_accession, $hit_url);
      }
      else {
        $hit_url .= $hit_accession;
      }
      // Catch exception in the event url is not valid.
      try {
        $hit_name = Link::fromTextAndUrl($hit_name, Url::fromUri($hit_url, [])); #->toString();
      }
      catch (\Exception $e) {
        // For example, "InvalidArgumentException: The URI
        // 'cv/lookup/{db}/{accession}' is invalid".
        // In this case, leave hit name unchanged.
      }
    }
    else {
    }
    return $hit_name;
  }

  /**
   * Builds the content for the popup window.
   *
   * @param array $blast_result
   *   Nested array with all blast results for one subject.
   * @param array $hit
   *   Nested array with the current hit.
   *
   * @return string
   *   HTML markup to display in the popup.
   */
  protected function buildPopupData(array $blast_result, array $hit): string {
    $hsps_array = $hit['hsp'] ?? [];

    // Find the number of digits in the largest coordinate.
    $ndigits = 1;
    foreach ($hsps_array AS $hsp) {
      if (strlen($hsp['query_from']) > $ndigits) {
        $ndigits = strlen($hsp['query_from']);
      }
      if (strlen($hsp['hit_from']) > $ndigits) {
        $ndigits = strlen($hsp['hit_from']);
      }
    }
    $sprintf_pattern = '%' . $ndigits . 'd';
    $midline_indent = str_repeat(' ', 8 + $ndigits);

    // Prepare some variables.
    $feature_name = $blast_result['feature_name'] ?? 'undefined';
    $db_name = $blast_result['db']['name'] ?? 'undefined';
    $hit_name = $hit['hit_name'] ?: $hit['accession'] ?: $hit['description'] ?: $this->t('no hit identifier');
    $hit_description = $hit['description'] ? ' (' . $hit['description'] . ')' : '';

    // Build the HTML markup.
    $markup = '<strong>BLAST of ' . $feature_name . ' vs. ' . $db_name . '</strong><br>';
    $markup .= 'Match: ' . $hit_name . $hit_description . '<br>';

    foreach ($hsps_array AS $hsp) {
      $markup .= '<strong>HSP ' . $hsp['hsp_num'] . '</strong>'
      . ' Score: ' . $hsp['bit_score'] . ' bits (' . $hsp['score'] . '),'
      . ' Expect = ' . sprintf('%.3e', floatval($hsp['evalue'])) . '<br>'
      . 'Identity = ' . sprintf("%d/%d (%.2f%%)", $hsp['identity'], $hsp['align_len'], $hsp['identity']/$hsp['align_len']*100) . ','
      . ' Postives = ' . sprintf("%d/%d (%.2f%%)", $hsp['positive'], $hsp['align_len'], $hsp['positive']/$hsp['align_len']*100) . ','
      . ' Query Frame = ' . $hsp['query_frame']
      . '<pre>'
      . 'Query: ' . sprintf($sprintf_pattern, $hsp['query_from']) . ' ' . $hsp['qseq'] . ' ' . sprintf("%d", $hsp['query_to']) . "\n"
      . $midline_indent . $hsp['midline'] . "\n"
      . 'Sbjct: ' . sprintf($sprintf_pattern, $hsp['hit_from']) . ' ' . $hsp['hseq'] . ' ' . sprintf("%d",$hsp['hit_to']) . '    '
      . '</pre>';
    }

    return $markup;
  }

  /**
   * Builds the data array to pass to javascript.
   *
   * @param array $blast_result
   *   Nested array with all blast results for one subject.
   * @param array $hit
   *   Nested array with the current hit.
   *
   * @return array
   *   Data to pass to the javascript.
   */
  protected function buildJavascriptData(array $blast_result, array $hit): array {
    $data = [];

    $hsp_pos = [];
    $hsps_array = $hit['hsp'] ?? [];
    foreach ($hsps_array AS $hsp) {
      $hsp_pos[] = [
        'x' => intval($hsp['query_from']),
        'y' => intval($hsp['query_to']),
        'description' => 'Expect = ' . sprintf('%.2e', floatval($hsp['evalue'])) . ', Id = ' . $hit['percent_identity'],
      ];
    }

    // In case the hitname is a long id, simplify it.
    $hit_name = $hit['accession'] ?: $hit['hit_name'] ?: $hit['description'] ?: $this->t('Name not specified');
    $clean_hit_name = preg_replace("/^([a-zA-Z0-9._-]+)\|([a-zA-Z0-9._-]+)\|([a-zA-Z0-9._-]+)\|([a-zA-Z0-9._-]+)\|$/", "$4", $hit_name);

    $hsp_pos_json = Json::encode($hsp_pos);

    $data['hsp_pos'] = $hsp_pos_json;
    $data['clean_name'] = $clean_hit_name;

    return $data;
  }

}
