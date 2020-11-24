<?php

namespace Drupal\entity_reference_actions\Element;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Element\Actions;

/**
 * Action element without primary classes.
 *
 * @RenderElement("simple_actions")
 */
class SimpleActions extends Actions {

  /**
   * {@inheritdoc}
   */
  public static function processActions(&$element, FormStateInterface $form_state, &$complete_form) {
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function preRenderActionsDropbutton(&$element, FormStateInterface $form_state, &$complete_form) {
    // Because we are cloning the elements into title sub element we need to
    // sort children first.
    $dropbuttons = [];
    foreach (Element::children($element, TRUE) as $key) {
      if (isset($element[$key]['#dropbutton'])) {
        $dropbutton = $element[$key]['#dropbutton'];

        if (!isset($dropbuttons[$dropbutton])) {
          $dropbuttons[$dropbutton] = [
            '#type' => 'ajax_dropbutton',
          ];
        }

        $dropbuttons[$dropbutton]['#links'][$key] = [
          'title' => $element[$key],
        ];

        // Clone the element as an operation.
        $operations[$key] = ['title' => $element[$key]];

        // Flag the original element as printed so it doesn't render twice.
        $element[$key]['#printed'] = TRUE;
      }
    }

    // @todo For now, all dropbuttons appear first. Consider to invent a more
    //   fancy sorting/injection algorithm here.
    return $dropbuttons + $element;
  }

}
