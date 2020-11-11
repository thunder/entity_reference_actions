<?php

namespace Drupal\Tests\entity_reference_actions\Functional;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityFormMode;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\media\Entity\Media;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\field\Traits\EntityReferenceTestTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\paragraphs\FunctionalJavascript\ParagraphsTestBaseTrait;

/**
 * Testing the widget.
 *
 * @group entity_reference_actions
 */
class SubformTest extends BrowserTestBase {

  use EntityReferenceTestTrait;
  use MediaTypeCreationTrait;
  use ParagraphsTestBaseTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_test',
    'media_library',
    'entity_reference_actions',
    'inline_entity_form',
    'paragraphs',
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
  protected $mediaImageType;

  /**
   * The test media.
   *
   * @var \Drupal\media\MediaInterface
   */
  protected $mediaImage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->mediaImageType = $this->createMediaType('image');
    $this->mediaImage = Media::create([
      'bundle' => $this->mediaImageType->id(),
      'published' => TRUE,
    ]);
    $this->mediaImage->save();

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
        $this->mediaImageType->id() => $this->mediaImageType->id(),
      ],
    ], FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    $sub_entity = EntityTest::create();
    $sub_entity->field_media = [$this->mediaImage];
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

    $this->assertTrue($this->mediaImage->isPublished());

    $this->drupalGet($entity->toUrl('edit-form'));
    $this->submitForm([], 'Unpublish all media items');

    $this->mediaImage = Media::load($this->mediaImage->id());
    $this->assertFalse($this->mediaImage->isPublished());

    $this->getSession()->getPage()->pressButton('Save');
    $entity = EntityTest::load($entity->id());

    $this->assertNotEmpty($entity->field_reference);
  }

  /**
   * Test a form with paragraphs and an IEF inside of it.
   *
   * @throws \Behat\Mink\Exception\ResponseTextException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function testParagraphsWithIef() {
    /** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $display_repository */
    $display_repository = \Drupal::service('entity_display.repository');

    $this->addParagraphsField('entity_test', 'paragraphs', 'entity_test', 'paragraphs');
    $this->addParagraphsType('test_paragraph');

    $galleryType = $this->createMediaType('image', ['id' => 'gallery']);
    $this->createEntityReferenceField('media', 'gallery', 'field_images', 'Images', 'media', 'default', [
      'target_bundles' => [
        $this->mediaImageType->id() => $this->mediaImageType->id(),
      ],
    ], FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    $display_repository->getFormDisplay('media', 'gallery')
      ->setComponent('field_images', [
        'type' => 'entity_reference_autocomplete',
        'third_party_settings' => ['entity_reference_actions' => ['enabled' => TRUE]],
      ])
      ->removeComponent($galleryType->getSource()->getSourceFieldDefinition($galleryType)->getName())
      ->save();

    $this->createEntityReferenceField('paragraph', 'test_paragraph', 'field_gallery', 'Gallery', 'media', 'default', [
      'target_bundles' => [
        $galleryType->id() => $galleryType->id(),
      ],
    ], FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    $display_repository->getFormDisplay('paragraph', 'test_paragraph')
      ->setComponent('field_gallery', [
        'type' => 'inline_entity_form_simple',
      ])->save();

    $mediaGallery = Media::create([
      'bundle' => $galleryType->id(),
      'published' => TRUE,
      'field_images' => [$this->mediaImage],
    ]);
    $mediaGallery->save();

    $paragraph = Paragraph::create([
      'type' => 'test_paragraph',
      'field_gallery' => [$mediaGallery],
    ]);
    $paragraph->save();

    $entity = EntityTest::create();
    $entity->paragraphs = [$paragraph];
    $entity->save();

    $this->drupalGet($entity->toUrl('edit-form'));

    $this->submitForm([], 'Unpublish all media items');

    $this->assertSession()->pageTextContains('Unpublish media was applied to 1 item');

    $this->mediaImage = Media::load($this->mediaImage->id());
    $this->assertFalse($this->mediaImage->isPublished());
  }

}
