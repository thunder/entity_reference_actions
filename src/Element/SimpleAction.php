<?php

namespace Drupal\entity_reference_actions\Element;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\Actions;

/**
 * Action element with primary classes.
 *
 * @RenderElement("simple_actions")
 */
class SimpleAction extends Actions {

  /**
   * {@inheritdoc}
   */
  public static function processActions(&$element, FormStateInterface $form_state, &$complete_form) {
    return $element;
  }

}
