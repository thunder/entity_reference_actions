<?php

namespace Drupal\entity_reference_actions\Element;

use Drupal\Core\Render\Element\Dropbutton;
use Drupal\Core\Render\Element\RenderElement;

/**
 * {@inheritdoc}
 *
 * @RenderElement("ajax_dropbutton")
 */
class AjaxDropbutton extends Dropbutton {

  /**
   * {@inheritdoc}
   */
  public static function preRenderDropbutton($element) {
    $element = parent::preRenderDropbutton($element);

    // Attach #ajax events if title is a render array.
    foreach ($element['#links'] as &$link) {
      if (isset($link['title']['#ajax'])) {
        $link['title'] = RenderElement::preRenderAjaxForm($link['title']);
      }
    }

    return $element;
  }

}
