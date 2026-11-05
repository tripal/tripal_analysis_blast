<?php

namespace Drupal\tripal_analysis_blast\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tripal\TripalField\Attribute\TripalFieldFormatter;
use Drupal\tripal_chado\TripalField\ChadoFormatterBase;

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
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    // Attaches the js for visualization as defined in
    // tripal_analysis_blast/tripal_analysis_blast.libraries.yml.
    $elements['#attached']['library'][] = 'tripal_analysis_blast/feature-viewer';

    foreach ($items as $delta => $item) {

      // Adds the placeholder for the blast hit.
      $elements[$delta]['feature_viewer'] = [
        '#markup' => '<div class="xxx-container" id="blast-hit-container"></div>',
      ];

      // Add the variables used by the javascript.
      $hit_data_json = $item->get('hit_data')->getString();
      $elements['#attached']['drupalSettings']['hitData'] = $hit_data_json;

      // @todo temporary placeholder for future blast visualization.
      $elements[$delta] = [
        "#markup" => '@todo NOT IMPLEMENTED: ' . $hit_data_json,
      ];
    }

    return $elements;
  }

}
