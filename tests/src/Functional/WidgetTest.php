<?php

namespace Drupal\Tests\entity_reference_actions\Functional;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\field\Traits\EntityReferenceTestTrait;
use Drupal\Tests\taxonomy\Traits\TaxonomyTestTrait;

/**
 * Testing the widget.
 *
 * @group entity_reference_actions
 */
class WidgetTest extends BrowserTestBase {

  use EntityReferenceTestTrait;
  use TaxonomyTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_test',
    'taxonomy',
    'entity_reference_actions',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The test vocabulary.
   *
   * @var \Drupal\taxonomy\VocabularyInterface
   */
  protected $vocabulary;

  /**
   * The test term.
   *
   * @var \Drupal\taxonomy\Entity\Term
   */
  protected $term;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->vocabulary = $this->createVocabulary();
    $this->term = $this->createTerm($this->vocabulary, ['published' => TRUE]);

    $handler_settings = [
      'target_bundles' => [
        $this->vocabulary->id() => $this->vocabulary->id(),
      ],
    ];
    $this->createEntityReferenceField('entity_test', 'entity_test', 'field_test', 'Test', 'taxonomy_term', 'default', $handler_settings, FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    $entity = EntityTest::create();
    $entity->field_test = [$this->term];
    $entity->save();

    $this->drupalLogin($this->createUser([
      'administer entity_test content',
      'administer taxonomy',
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
      ->setComponent('field_test', ['type' => $widget])
      ->save();

    $this->drupalGet('/entity_test/manage/1/edit');

    $this->assertSession()
      ->fieldExists('field_test_wrapper[entity_reference_actions][options]');

    $this->assertTrue($this->term->isPublished());

    $edit = [
      'field_test_wrapper[entity_reference_actions][options]' => 'taxonomy_term_unpublish_action',
    ];
    $this->drupalPostForm('/entity_test/manage/1/edit', $edit, 'field_test_button');

    $this->term = Term::load($this->term->id());
    $this->assertFalse($this->term->isPublished());

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
    ];
  }

}
