<?php

namespace Drupal\entity_reference_actions;

use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsButtonsWidget;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsSelectWidget;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsWidgetBase;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\system\ActionConfigEntityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\HttpKernelInterface;

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
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The UUID service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuidGenerator;

  /**
   * The HTTP kernel service.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected $httpKernel;

  /**
   * All available options for this entity_type.
   *
   * @var \Drupal\system\ActionConfigEntityInterface[]
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
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\Component\Uuid\UuidInterface $uuidGenerator
   *   The UUID generator service.
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $httpKernel
   *   The HTTP kernel service.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, AccountProxyInterface $currentUser, RequestStack $requestStack, UuidInterface $uuidGenerator, HttpKernelInterface $httpKernel) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
    $this->requestStack = $requestStack;
    $this->uuidGenerator = $uuidGenerator;
    $this->httpKernel = $httpKernel;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('entity_type.manager'), $container->get('current_user'), $container->get('request_stack'), $container->get('uuid'), $container->get('http_kernel'));
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
    if (!$this->settings['enabled']) {
      return;
    }
    /** @var \Drupal\Core\Field\FieldItemListInterface $items */
    $items = $context['items'];
    $field_definition = $items->getFieldDefinition();

    $uuid = 'entity_reference_actions-' . $this->uuidGenerator->generate();

    $form_state->set($uuid, $context);

    $element['entity_reference_actions_messages'] = [
      '#type' => 'container',
      '#attributes' => ['data-entity-reference-actions-messages' => ''],
    ];

    $element['entity_reference_actions'] = [
      '#type' => 'simple_actions',
      '#uuid' => $uuid,
      '#attached' => [
        'library' => [
          'core/drupal.dialog.ajax',
          'core/drupal.states',
        ],
      ],
      '#states' => [
        'visible' => $this->getVisibleStateConditions($element, $context),
      ],
    ];

    $bulk_options = $this->getBulkOptions();
    foreach ($bulk_options as $id => $label) {
      $element['entity_reference_actions'][$id] = [
        '#type' => 'submit',
        '#limit_validation_errors' => [$element['widget']['#parents']],
        '#submit' => [],
        '#id' => $field_definition->getName() . '_' . $id . '_button',
        '#name' => $field_definition->getName() . '_' . $id . '_button',
        '#value' => $label,
        '#ajax' => [
          'callback' => [$this, 'submitForm'],
        ],
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
    // The field name we are acting on, deep from the form structure.
    $field_name = end($parents);

    $parents = array_slice($parents, 0, -1);
    $values = NestedArray::getValue($form, $parents);

    $context = $form_state->get($values[$field_name]['entity_reference_actions']['#uuid']);

    /** @var \Drupal\Core\Field\FieldItemListInterface $items */
    $items = $context['items'];

    $context['widget']->extractFormValues($items, $values, $form_state);

    $action = $this->actions[end($button['#array_parents'])];

    $ids = array_filter(!$items->isEmpty() ? array_column($items->getValue(), 'target_id') : []);

    $entities = $this->entityTypeManager->getStorage($items->getSettings()['target_type'])
      ->loadMultiple($ids);

    $commands = [];
    $entities = array_filter($entities, function ($entity) use ($action, &$commands) {
      if (!$action->getPlugin()->access($entity, $this->currentUser)) {
        $commands[] = new MessageCommand($this->t('No access to execute %action on the @entity_type_label %entity_label.', [
          '%action' => $action->label(),
          '@entity_type_label' => $entity->getEntityType()->getLabel(),
          '%entity_label' => $entity->label(),
        ]), '[data-entity-reference-actions-messages]', ['type' => 'warning'], FALSE);
        return FALSE;
      }
      return TRUE;
    });

    $response = new AjaxResponse();
    if ($entities) {
      $dialog_options = [
        'height' => '75%',
        'width' => '75%',
      ];
      $operation_definition = $action->getPluginDefinition();
      if (!empty($operation_definition['confirm_form_route_name'])) {
        $action->getPlugin()->executeMultiple($entities);

        $request = $this->requestStack->getCurrentRequest();
        $dialog_url = Url::fromRoute($operation_definition['confirm_form_route_name'], [MainContentViewSubscriber::WRAPPER_FORMAT => 'drupal_modal'])->toString(TRUE);
        $parameter = [
          'ajax_page_state' => $request->request->get('ajax_page_state'),
          'dialogOptions' => $dialog_options,
        ];
        $sub_request = Request::create($dialog_url->getGeneratedUrl(), 'POST', $parameter, [], [], $request->server->all());
        if ($request->getSession()) {
          $sub_request->setSession($request->getSession());
        }

        /** @var \Drupal\Core\Ajax\AjaxResponse $response */
        $response = $this->httpKernel->handle($sub_request, HttpKernelInterface::SUB_REQUEST);

        // We have to clear the response data, otherwise the new commands will
        // not be returned.
        $response->setContent("{}");
      }
      else {
        $batch_builder = (new BatchBuilder())
          ->setTitle($this->getActionLabel($action))
          ->setFinishCallback([__CLASS__, 'batchFinish']);
        foreach ($entities as $entity) {
          $batch_builder->addOperation([__CLASS__, 'batchCallback'], [
            $entity->id(),
            $entity->getEntityTypeId(),
            $action->id(),
          ]);
        }

        batch_set($batch_builder->toArray());
        batch_process();

        require_once DRUPAL_ROOT . '/core/includes/batch.inc';
        $batch_page = _batch_progress_page();
        $batch_page['#attached']['library'] = ['entity_reference_actions/batch'];

        $response->addCommand(new OpenModalDialogCommand($this->getActionLabel($action), $batch_page, $dialog_options));
      }
    }
    else {
      $entity_type = $this->entityTypeManager->getDefinition($this->entityTypeId);
      $commands[] = new MessageCommand($this->t('No @entity_type_label selected.', [
        '@entity_type_label' => $entity_type->getPluralLabel(),
      ]), '[data-entity-reference-actions-messages]', ['type' => 'warning']);
    }

    // Attach existing commands.
    foreach ($commands as $command) {
      $response->addCommand($command);
    }
    return $response;

  }

  /**
   * The batch finish callback.
   *
   * @throws \Drupal\Core\Form\EnforcedResponseException
   */
  public static function batchFinish() {
    $batch = &batch_get();

    // Copied form _batch_finished().
    \Drupal::service('batch.storage')->delete($batch['id']);
    foreach ($batch['sets'] as $batch_set) {
      if ($queue = _batch_queue($batch_set)) {
        $queue->deleteQueue();
      }
    }
    // Clean-up the session. Not needed for CLI updates.
    if (isset($_SESSION)) {
      unset($_SESSION['batches'][$batch['id']]);
      if (empty($_SESSION['batches'])) {
        unset($_SESSION['batches']);
      }
    }

    $response = new AjaxResponse();
    $response->addCommand(new CloseModalDialogCommand());
    $response->addCommand(new MessageCommand(t('%action was successfully applied.', ['%action' => $batch['sets'][0]['title']]), '[data-entity-reference-actions-messages]'));

    // _batch_finished() only accepts a RedirectResponse. There is no
    // other chance to return our AjaxResponse without throwing this exception.
    throw new EnforcedResponseException($response);
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

    /** @var \Drupal\system\ActionConfigEntityInterface $action */
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
      $options[$id] = $this->getActionLabel($action);
    }

    return $options;
  }

  /**
   * Returns the label for an action.
   *
   * @param \Drupal\system\ActionConfigEntityInterface $action
   *   The action.
   *
   * @return string
   *   The action label.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getActionLabel(ActionConfigEntityInterface $action) {
    $entity_type = $this->entityTypeManager->getDefinition($this->entityTypeId);

    $label = $action->label();
    if (isset($action->getPlugin()->getPluginDefinition()['action_label'])) {
      $label = sprintf('%s all %s', $action->getPlugin()->getPluginDefinition()['action_label'], $entity_type->getPluralLabel());
    }
    return $label;
  }

  /**
   * Submit form dialog #ajax callback.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   An AJAX response that display validation error messages or represents a
   *   successful submission.
   */
  public static function dialogAjaxSubmit(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    if ($form_state->hasAnyErrors()) {
      $form['status_messages'] = [
        '#type' => 'status_messages',
        '#weight' => -1000,
      ];
      $form['#sorted'] = FALSE;
      $response->addCommand(new ReplaceCommand('[data-drupal-selector="' . $form['#attributes']['data-drupal-selector'] . '"]', $form));
    }
    else {
      $response->addCommand(new MessageCommand(t('Action was successfully applied.'), '[data-entity-reference-actions-messages]'));
      $response->addCommand(new CloseModalDialogCommand());
    }
    return $response;
  }

  /**
   * Get the conditions to show the ERA button.
   *
   * @param array $element
   *   The element with the attached action form.
   * @param array $context
   *   The context of this form.
   */
  protected function getVisibleStateConditions(array $element, array $context) {
    /** @var \Drupal\Core\Field\FieldItemListInterface $items */
    $items = $context['items'];

    $parents = $element['widget']['#parents'];

    $first_parent = array_shift($parents) . '[';
    $secondary_parents = '';
    if ($parents) {
      $secondary_parents = implode('][', $parents) . ']';
    }

    $field_selector = 'name^="' . $first_parent . $secondary_parents . '"';
    $state_selector = [":input[$field_selector]" => ['filled' => TRUE]];
    $multiple = $items->getFieldDefinition()->getFieldStorageDefinition()->isMultiple();
    if ($context['widget'] instanceof OptionsWidgetBase) {
      $state_selector = [":input[$field_selector]" => ['checked' => TRUE]];
    }
    if ($context['widget'] instanceof OptionsButtonsWidget && !$multiple) {
      $state_selector = [":input[$field_selector]" => ['!value' => isset($element['widget']['#empty_value']) ? $element['widget']['#empty_value'] : '_none']];
    }
    if ($context['widget'] instanceof OptionsSelectWidget) {
      $state_selector = ["select[$field_selector]" => ['!value' => isset($element['widget']['#empty_value']) ? $element['widget']['#empty_value'] : '_none']];

      // States doesn't work for a multiple select lists.
      // See https://www.drupal.org/project/drupal/issues/1149078
      if ($multiple) {
        $state_selector = [];
      }
    }
    return $state_selector;
  }

}
