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

    // @todo Make this value configurable.
    $num_results_per_page = 10;
    # $feature_pager_id = 15; // increment by 1 for each database.
    $pager_index = 0;
    # $list_items = [];

    foreach ($items as $delta => $item) {

      // Retrieve the field data.
      $blast_results = Json::decode($item->get('hit_data')->getString());

      // Each result here would be a query to a particular BLAST database.
      foreach ($blast_results as $blast_result) {

        $hits_array = $blast_result['hits_array'];
        $db = $blast_result['db'];
        $analysis = $blast_result['analysis'];
        $total_records = count($hits_array ?? []);

        // Set up a pager for this blast result.
        $pager = $pager_manager->createPager($total_records, $num_results_per_page, $pager_index);
        $current_page_num = $pager->getCurrentPage();
        $offset = $num_results_per_page * $current_page_num;

        // Result title. If the linked analysis is published, make it clickable.
        $result_name = 'BLAST of ' . $blast_result['feature_name'] . ' vs. ' . $db['name'];
        if ($analysis['entity_id']) {
          $analysis_url = 'base://bio_data/' . $analysis['entity_id'];
          $result_name = Link::fromTextAndUrl($result_name, Url::fromUri($analysis_url, []))->toString();
        }
        $result_description = '<br>Analysis Date: '
          . preg_replace("/^(\d+-\d+-\d+) .*/", "$1", $analysis['timeexecuted'])
          . '<br>Total hits: '
          . number_format($total_records);

        // Add a form element for the title and description.
        $elements[$delta]['description'] = [
          '#type' => 'container',
          '#attributes' => [
            '#id' => $analysis['analysis_id'],
          ],
          'body' => [
            '#markup' => '<strong>' . $result_name . '</strong>' . $result_description,
          ],
        ];

        // Adds the placeholder for the feature viewer visualization.
        // The element identifier is based on $delta.
        $el_id = 'blast-hit-container-' . $delta;
        $elements[$delta]['feature_viewer'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => 'feature-viewer-container',
            'id' => $el_id,
          ],
        ];

        // Build the table of hits for the current page of the pager.
        $header = ['Match Name', 'Expect', 'Identity', 'Description', ''];
        $rows = [];
        for ($hit_index = $offset; $hit_index < $offset + $num_results_per_page && $hit_index < $total_records; $hit_index++) {
          $row_id = 'hsp-link-' . $analysis['analysis_id'] . '-' . $hit_index;
          $hit = $hits_array[$hit_index];

          // Values that are used for all blast results.
          if ($hit_index == $offset) {
            $elements['#attached']['drupalSettings']['tripal_analysis_blast']['query_data']['residues'] = $blast_result['feature_residues'];
          }

          // The hit name for table display, this is a link when possible.
          $hit_name = $this->makeHitLinkOut($hit);

          // Provide a shortened description for table display.
          $full_description = $hit['description'] ?: $this->t('No description');
          $description_trim_length = 50;
          $short_description = substr($full_description, 0, $description_trim_length);
          if (strlen($full_description) > $description_trim_length) {
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

          // The details button will trigger javascript
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

          // Add the variables used by the feature viewer javascript,
          // under an element identifier based on $delta.
          $elements['#attached']['drupalSettings']['tripal_analysis_blast']['hit_data'][$el_id][$hit_index]
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
        $elements[$delta]['pager'] = [
          '#type' => 'pager',
          '#element' => $pager_index,
        ];

        // Increment the pager index.
        $pager_index++;
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
    $hit_name = $this->getHitName($hit);
    $hit_url = $hit['hit_url'] ?? NULL;
    $hit_accession = $hit['accession'] ?? NULL;
    // @todo db not yet implemented in hit array.
    $hit_db = $hit['db'] ?? NULL;
    if ($hit_url && $hit_accession) {
      if ($hit_db) {
        $hit_url = preg_replace('/{db}/', $hit_db, $hit_url);
      }
      // If accession placeholder is present, substitute, otherwise append.
      if (preg_match('/{accession}/', $hit_accession)) {
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
    // @todo Make priority of hit name or accession configurable.
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
    foreach ($hsps_array as $hsp) {
      $hsp_pos[] = [
        'x' => intval($hsp['query_from']),
        'y' => intval($hsp['query_to']),
        'description' => 'Expect = ' . sprintf('%.2e', floatval($hsp['evalue'])) . ', Id = ' . $hit['percent_identity'],
      ];
    }

    $hit_name = $hit['accession'] ?: $hit['hit_name'] ?: $hit['description'] ?: $this->t('Name not specified');

    // In case the hitname is a long id, simplify it.
    $hit_clean_name = preg_replace("/^([a-zA-Z0-9._-]+)\|([a-zA-Z0-9._-]+)\|([a-zA-Z0-9._-]+)\|([a-zA-Z0-9._-]+)\|$/", "$4", $hit_name);

    $data['hsp_pos'] = $hsp_pos;
    $data['clean_name'] = $hit_clean_name;
    // @todo Make color configurable.
    $data['color'] = "#0F8292";

    return $data;
  }

  /**
   * Get the preferred hit name.
   *
   * @param array $hit
   *   A single blast hit array.
   *
   * @return string
   *   The name of the hit.
   */
  protected function getHitName(array $hit): string {
    // @todo Make priority of hit_name or accession configurable.
    $hit_name = $hit['accession'] ?: $hit['hit_name'] ?: $hit['description'] ?: $this->t('no hit id!');
    return $hit_name;
  }

}
