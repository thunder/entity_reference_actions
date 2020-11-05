<?php

namespace Drupal\entity_reference_actions;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides the form functions to call actions on referenced entities.
 */
class EntityReferenceActionsHandler implements ContainerInjectionInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

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
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * All available options for this entity_type.
   *
   * @var array
   */
  protected $actions = [];

  /**
   * Third party settings for this form.
   *
   * @var array
   */
  protected $settings = [];

  /**
   * The entity type ID.
   *
   * @var string
   */
  protected $entityTypeId;

  /**
   * EntityReferenceActionsHandler constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, AccountProxyInterface $currentUser, MessengerInterface $messenger, RequestStack $requestStack) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
    $this->messenger = $messenger;
    $this->requestStack = $requestStack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('entity_type.manager'), $container->get('current_user'), $container->get('messenger'), $container->get('request_stack'));
  }

  /**
   * Initialize the form.
   *
   * @param string $entity_type_id
   *   Entity type of this field.
   * @param mixed[] $settings
   *   Third party settings.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function init($entity_type_id, array $settings) {
    $this->entityTypeId = $entity_type_id;
    $actionStorage = $this->entityTypeManager->getStorage('action');
    $this->actions = array_filter($actionStorage->loadMultiple(), function ($action) {
      return $action->getType() == $this->entityTypeId;
    });

    $this->settings = NestedArray::mergeDeepArray([
      [
        'enabled' => FALSE,
        'options' => [
          'action_title' => $this->t('Action'),
          'include_exclude' => 'exclude',
          'selected_actions' => [],
        ],
      ],
      $settings,
    ]);
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
   */
  public function formAlter(array &$element, FormStateInterface $form_state, array $context) {

    /** @var \Drupal\Core\Field\FieldItemListInterface $items */
    $items = $context['items'];
    if ($items->isEmpty() || !$this->settings['enabled']) {
      return;
    }

    $field_definition = $items->getFieldDefinition();
    $field_id = $field_definition->getUniqueIdentifier();

    $context['widget']::setWidgetState([], $field_id, $form_state, $context);

    $element['entity_reference_actions'] = [
      '#type' => 'simple_actions',
      '#field_id' => $field_id,
    ];

    $bulk_options = $this->getBulkOptions();
    foreach ($bulk_options as $id => $label) {
      // Add another option to go to the AMP page after saving.
      $element['entity_reference_actions'][$id] = [
        '#type' => 'submit',
        '#name' => $field_definition->getName() . '_' . $id . '_button',
        '#value' => $label,
        '#submit' => [[$this, 'submitForm']],
      ];
      if (count($bulk_options) > 1) {
        $element['entity_reference_actions'][$id]['#dropbutton'] = 'bulk_edit';
      }
    }
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

    $parents = array_slice($button['#array_parents'], 0, -2);
    $field_name = end($parents);

    $parents = array_slice($parents, 0, -1);
    $sub_form = NestedArray::getValue($form, $parents);

    $field_id = $sub_form[$field_name]['entity_reference_actions']['#field_id'];

    $state = WidgetBase::getWidgetState([], $field_id, $form_state);

    /** @var \Drupal\Core\Field\FieldItemListInterface $items */
    $items = $state['items'];

    $state['widget']->extractFormValues($items, $sub_form, $form_state);

    if (!empty($items->getValue())) {

      $action = $this->entityTypeManager->getStorage('action')
        ->load(end($button['#array_parents']));

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

      $request = $this->requestStack->getCurrentRequest();
      $url = Url::fromUserInput($request->getPathInfo(), ['query' => $request->query->all()]);
      $options = [
        'query' => ['destination' => $url->toString()],
      ];
      if ($request->query->has('destination')) {
        $request->query->remove('destination');
      }

      $operation_definition = $action->getPluginDefinition();
      if (!empty($operation_definition['confirm_form_route_name'])) {
        $action->getPlugin()->executeMultiple($entities);
        $form_state->setRedirect($operation_definition['confirm_form_route_name'], [], $options);
      }
      else {
        $batch_builder = (new BatchBuilder())
          ->setTitle($this->formatPlural(count($entities), 'Apply %action action to @count item.', 'Apply %action action to @count items.', [
            '%action' => $action->label(),
          ]));
        foreach ($entities as $entity) {
          $batch_builder->addOperation([__CLASS__, 'batchCallback'], [
            $entity->id(),
            $entity->getEntityTypeId(),
            $action->id(),
          ]);
        }

        batch_set($batch_builder->toArray());

        // Don't display the message unless there are some elements affected and
        // there is no confirmation form.
        $this->messenger->addStatus($this->formatPlural(count($entities), '%action was applied to @count item.', '%action was applied to @count items.', [
          '%action' => $action->label(),
        ]));
        $form_state->setRedirectUrl($url);
      }
    }
  }

  /**
   * Call action in batch.
   *
   * @param int $entity_id
   *   The entity ID.
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $action_id
   *   The action ID.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public static function batchCallback($entity_id, $entity_type_id, $action_id) {
    $entity_type_manager = \Drupal::entityTypeManager();

    /** @var \Drupal\Core\Action\ActionInterface $action */
    $action = $entity_type_manager->getStorage('action')->load($action_id);

    $entity = $entity_type_manager->getStorage($entity_type_id)->load($entity_id);

    $action->getPlugin()->executeMultiple([$entity]);
  }

  /**
   * Build the settings form.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   * @param string $field_name
   *   The field name.
   */
  public function buildSettingsForm(array &$form, FormStateInterface $form_state, $field_name) {
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Entity Reference Actions'),
      '#default_value' => $this->settings['enabled'],
    ];

    $form['options'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Entity Reference Actions settings'),
      '#states' => [
        'visible' => [
          ':input[name="fields[' . $field_name . '][settings_edit_form][third_party_settings][entity_reference_actions][enabled]"]' => [
            'checked' => TRUE,
          ],
        ],
      ],
    ];

    $form['options']['action_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Action title'),
      '#default_value' => $this->settings['options']['action_title'],
      '#description' => $this->t('The title shown above the actions dropdown.'),
    ];

    $form['options']['include_exclude'] = [
      '#type' => 'radios',
      '#title' => $this->t('Available actions'),
      '#options' => [
        'exclude' => $this->t('All actions, except selected'),
        'include' => $this->t('Only selected actions'),
      ],
      '#default_value' => $this->settings['options']['include_exclude'],
    ];
    $form['options']['selected_actions'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Selected actions'),
      '#options' => $this->getBulkOptions(FALSE),
      '#default_value' => $this->settings['options']['selected_actions'],
    ];
  }

  /**
   * Returns the available operations for this form.
   *
   * @param bool $filtered
   *   (optional) Whether to filter actions to selected actions.
   *
   * @return array
   *   An associative array of operations, suitable for a select element.
   *
   * @see \Drupal\views\Plugin\views\field\BulkForm
   */
  protected function getBulkOptions($filtered = TRUE) {
    $options = [];
    $entity_type = $this->entityTypeManager->getDefinition($this->entityTypeId);
    // Filter the action list.
    /** @var \Drupal\system\ActionConfigEntityInterface $action */
    foreach ($this->actions as $id => $action) {
      if ($filtered) {
        $in_selected = in_array($id, array_filter($this->settings['options']['selected_actions']));
        // If the field is configured to include only the selected actions,
        // skip actions that were not selected.
        if (($this->settings['options']['include_exclude'] == 'include') && !$in_selected) {
          continue;
        }
        // Otherwise, if the field is configured to exclude the selected
        // actions, skip actions that were selected.
        elseif (($this->settings['options']['include_exclude'] == 'exclude') && $in_selected) {
          continue;
        }
      }
      $label = $action->label();
      if (isset($action->getPlugin()->getPluginDefinition()['action_label'])) {
        $label = sprintf('%s all %s', $action->getPlugin()->getPluginDefinition()['action_label'], $entity_type->getPluralLabel());
      }
      $options[$id] = $label;
    }

    return $options;
  }

}
