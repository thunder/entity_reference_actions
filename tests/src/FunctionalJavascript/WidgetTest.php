<?php

namespace Drupal\Tests\entity_reference_actions\FunctionalJavascript;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\media\Entity\Media;
use Drupal\Tests\field\Traits\EntityReferenceTestTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;

/**
 * Testing the widget.
 *
 * @group entity_reference_actions
 */
class WidgetTest extends WebDriverTestBase {

  use EntityReferenceTestTrait;
  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_test',
    'media_library',
    'entity_reference_actions',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The test type.
   *
   * @var \Drupal\media\MediaTypeInterface
   */
  protected $mediaType;

  /**
   * The test media.
   *
   * @var \Drupal\media\MediaInterface
   */
  protected $media;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->mediaType = $this->createMediaType('image');
    $this->media = Media::create([
      'bundle' => $this->mediaType->id(),
      'published' => TRUE,
    ]);

    $handler_settings = [
      'target_bundles' => [
        $this->mediaType->id() => $this->mediaType->id(),
      ],
    ];
    $this->createEntityReferenceField('entity_test', 'entity_test', 'field_test', 'Test', 'media', 'default', $handler_settings, FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    $entity = EntityTest::create();
    $entity->field_test = [$this->media];
    $entity->save();

    $this->drupalLogin($this->createUser([
      'administer entity_test content',
      'administer media',
    ]));
  }

  /**
   * Tests different widgets.
   *
   * @dataProvider providerTestWidgets
   */
  public function testWidgets($widget) {

    /** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $display_repository */
    $display_repository = \Drupal::service('entity_display.repository');
    $display_repository->getFormDisplay('entity_test', 'entity_test')
      ->setComponent('field_test', [
        'type' => $widget,
        'third_party_settings' => ['entity_reference_actions' => ['enabled' => TRUE]],
      ])
      ->save();

    $this->drupalGet('/entity_test/manage/1/edit');

    $this->assertTrue($this->media->isPublished());

    $this->getSession()->getPage()->find('css', 'li.dropbutton-toggle button')->click();
    $this->getSession()->getPage()->pressButton('Unpublish all media items');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->assertSession()->pageTextContains('Action was successful applied');

    $this->media = Media::load($this->media->id());
    $this->assertFalse($this->media->isPublished());

    $this->getSession()->getPage()->pressButton('Save');
    $entity = EntityTest::load(1);

    $this->assertNotEmpty($entity->field_test);
  }

  /**
   * Provides test data for testWidgets().
   */
  public function providerTestWidgets() {
    return [
      ['entity_reference_autocomplete_tags'],
      ['entity_reference_autocomplete'],
      ['options_select'],
      ['options_buttons'],
      ['media_library_widget'],
    ];
  }

  /**
   * Test an action with confirmation page.
   */
  public function testConfirmationAction() {
    /** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $display_repository */
    $display_repository = \Drupal::service('entity_display.repository');
    $display_repository->getFormDisplay('entity_test', 'entity_test')
      ->setComponent('field_test', [
        'type' => 'media_library_widget',
        'third_party_settings' => ['entity_reference_actions' => ['enabled' => TRUE]],
      ])
      ->save();

    $this->drupalGet('/entity_test/manage/1/edit');

    $this->getSession()->getPage()->pressButton('Delete all media items');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->assertSession()->elementExists('css', '.ui-dialog-buttonpane')->pressButton('Delete');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->assertSession()->pageTextContains('Action was successful applied');

    $this->assertEmpty(Media::load($this->media->id()));
  }

}
