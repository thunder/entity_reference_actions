<?php

namespace Drupal\Tests\entity_reference_actions\Functional;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityFormMode;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\media\Entity\Media;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\field\Traits\EntityReferenceTestTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;

/**
 * Testing the widget.
 *
 * @group entity_reference_actions
 */
class SubformTest extends BrowserTestBase {

  use EntityReferenceTestTrait;
  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_test',
    'media_library',
    'entity_reference_actions',
    'inline_entity_form',
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
    $this->media->save();

    EntityFormMode::create([
      'id' => 'entity_test.inline',
      'targetEntityType' => 'entity_test',
    ])->save();
    $entity_form_display = EntityFormDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'inline',
      'status' => TRUE,
    ]);
    $entity_form_display->save();

    $this->drupalLogin($this->createUser([
      'administer entity_test content',
      'administer media',
    ]));
  }

  /**
   * Test in inline entity form.
   */
  public function testInlineEntityForm() {

    $this->createEntityReferenceField('entity_test', 'entity_test', 'field_reference', 'Reference', 'entity_test', 'default', [
      'target_bundles' => [
        'entity_test' => 'entity_test',
      ],
    ], FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    $this->createEntityReferenceField('entity_test', 'entity_test', 'field_media', 'Media', 'media', 'default', [
      'target_bundles' => [
        $this->mediaType->id() => $this->mediaType->id(),
      ],
    ], FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    $sub_entity = EntityTest::create();
    $sub_entity->field_media = [$this->media];
    $sub_entity->save();

    $entity = EntityTest::create();
    $entity->field_reference = [$sub_entity];
    $entity->save();

    /** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $display_repository */
    $display_repository = \Drupal::service('entity_display.repository');
    $display_repository->getFormDisplay('entity_test', 'entity_test', 'inline')
      ->setComponent('field_media', [
        'type' => 'entity_reference_autocomplete',
        'third_party_settings' => ['entity_reference_actions' => ['enabled' => TRUE]],
      ])->save();

    $display_repository->getFormDisplay('entity_test', 'entity_test')
      ->setComponent('field_reference', [
        'type' => 'inline_entity_form_simple',
        'settings' => [
          'form_mode' => 'inline',
        ],
      ])->save();

    $this->assertTrue($this->media->isPublished());

    $this->drupalGet($entity->toUrl('edit-form'));
    $this->submitForm([], 'Unpublish all media items');

    $this->media = Media::load($this->media->id());
    $this->assertFalse($this->media->isPublished());

    $this->getSession()->getPage()->pressButton('Save');
    $entity = EntityTest::load($entity->id());

    $this->assertNotEmpty($entity->field_reference);
  }

}
