<?php

namespace Drupal\entity_reference_actions\Form;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RedirectDestinationTrait;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the form functions to call actions on referenced entities.
 */
class ActionForm implements ContainerInjectionInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;
  use RedirectDestinationTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * ActionForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, AccountProxyInterface $currentUser, MessengerInterface $messenger) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('entity_type.manager'), $container->get('current_user'), $container->get('messenger'));
  }

  /**
   * Build the form elements.
   *
   * @param array $element
   *   The element with the attached action form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $context
   *   The context of this form.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function formAlter(array &$element, FormStateInterface $form_state, array $context) {

    /** @var \Drupal\Core\Field\FieldItemListInterface $items */
    $items = $context['items'];
    if ($items->isEmpty()) {
      return;
    }

    $field_definition = $items->getFieldDefinition();

    $entity_type = $field_definition->getSettings()['target_type'];

    $actionStorage = $this->entityTypeManager->getStorage('action');
    $actions = array_filter($actionStorage->loadMultiple(), function ($action) use ($entity_type) {
      return $action->getType() == $entity_type;
    });

    if (empty($actions)) {
      return;
    }

    $context['widget']::setWidgetState($element['#parents'], $field_definition->getName(), $form_state, $context);

    $options = [];
    foreach ($actions as $id => $action) {
      $options[$id] = $action->label();
    }

    $element += ['#tree' => TRUE];

    $element['entity_reference_actions'] = [
      '#type' => 'container',
    ];

    $element['entity_reference_actions']['options'] = [
      '#type' => 'select',
      '#title' => $this->t('Available actions'),
      '#options' => $options,
    ];

    $element['entity_reference_actions']['submit'] = [
      '#type' => 'submit',
      '#name' => $field_definition->getName() . '_button',
      '#value' => $this->t('Apply to referenced items'),
      '#submit' => [[$this, 'submitForm']],
    ];

  }

  /**
   * Submit function to call the action.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $button = $form_state->getTriggeringElement();
    $field_name = reset($button['#array_parents']);

    /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $form_display */
    $form_display = $form_state->getStorage()['form_display'];

    /** @var \Drupal\Core\Field\WidgetInterface $widget */
    $widget = $form_display->getRenderer($field_name);

    /** @var \Drupal\Core\Field\FieldItemListInterface $items */
    $items = $widget::getWidgetState($form[$field_name]['#parents'], $field_name, $form_state)['items'];

    $widget->extractFormValues($items, $form, $form_state);

    if (!empty($items->getValue())) {
      $wrapper = $form_state->getValues()[$field_name . '_wrapper'];

      $action = $this->entityTypeManager->getStorage('action')
        ->load($wrapper['entity_reference_actions']['options']);

      $ids = array_filter(array_column($items->getValue(), 'target_id'));

      $entities = $this->entityTypeManager->getStorage($items->getSettings()['target_type'])
        ->loadMultiple($ids);

      $entities = array_filter($entities, function ($entity) use ($action) {
        if (!$action->getPlugin()->access($entity, $this->currentUser)) {
          $this->messenger->addError($this->t('No access to execute %action on the @entity_type_label %entity_label.', [
            '%action' => $action->label(),
            '@entity_type_label' => $entity->getEntityType()->getLabel(),
            '%entity_label' => $entity->label(),
          ]));
          return FALSE;
        }
        return TRUE;
      });

      $action->execute($entities);

      $operation_definition = $action->getPluginDefinition();
      if (!empty($operation_definition['confirm_form_route_name'])) {
        $options = [
          'query' => $this->getDestinationArray(),
        ];
        $form_state->setRedirect($operation_definition['confirm_form_route_name'], [], $options);
      }
      else {
        // Don't display the message unless there are some elements affected and
        // there is no confirmation form.
        $this->messenger->addStatus($this->formatPlural(count($entities), '%action was applied to @count item.', '%action was applied to @count items.', [
          '%action' => $action->label(),
        ]));
      }
    }

  }

}
