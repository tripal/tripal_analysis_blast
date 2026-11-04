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

    // Use render arrays to generate markup for your field.
    foreach ($items as $delta => $item) {
      $hit_data_json = $item->get('hit_data')->getString();
      // @todo temporary placeholder for future blast visualization.
      $elements[$delta] = [
        "#markup" => '@todo NOT IMPLEMENTED: ' . $hit_data_json,
      ];
    }

    return $elements;
  }

}
