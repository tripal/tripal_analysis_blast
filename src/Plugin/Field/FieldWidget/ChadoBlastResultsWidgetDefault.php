<?php

namespace Drupal\tripal_analysis_blast\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tripal\TripalField\Attribute\TripalFieldWidget;
use Drupal\tripal_chado\TripalField\ChadoWidgetBase;

/**
 * Plugin implementation of the 'format_blast_results_widget' field widget.
 *
 * This widget is not functional for editing as blast hit data is
 * complex and needs to be imported, so data is just passed through
 * the form as value fields.
 */
#[TripalFieldWidget(
  id: 'chado_blast_results_widget_default',
  label: new TranslatableMarkup('Format Blast Results Widget'),
  description: new TranslatableMarkup('Placeholder Widget'),
  field_types: [
    'chado_blast_results',
  ],
)]
class ChadoBlastResultsWidgetDefault extends ChadoWidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {

    $item_vals = $items[$delta]->getValue();
    $record_id = $item_vals['record_id'] ?? 0;
    $hit_data = $item_vals['hit_data'] ?? '';

    $element['record_id'] = [
      '#type' => 'value',
      '#value' => $record_id,
    ];
    $element['hit_data'] = [
      '#type' => 'value',
      '#value' => $hit_data,
    ];

    return $element;
  }

  /**
   * {@inheritDoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    // Remove if there is no hit data for this record.
    foreach ($values as $delta => $item) {
      if ($item['hit_data'] == '') {
        unset($values[$delta]);
      }
    }
    return $values;
  }

}
