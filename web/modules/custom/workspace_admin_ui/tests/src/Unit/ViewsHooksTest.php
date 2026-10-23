<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_admin_ui\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\workspace_admin_ui\Hook\ViewsHooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the status field preprocess workaround for gin's template.
 *
 * Gin's views-view-field--status.html.twig reads the row translation from the
 * row property node_field_data_langcode, which a monolingual site does not
 * have. The hook fills it in from the entity of the row.
 *
 * @see \Drupal\workspace_admin_ui\Hook\ViewsHooks::preprocessViewsViewField()
 * @see web/themes/contrib/gin/templates/views/views-view-field--status.html.twig
 */
#[Group('workspace_admin_ui')]
#[CoversClass(ViewsHooks::class)]
class ViewsHooksTest extends UnitTestCase {

  /**
   * The hook implementation.
   */
  protected ViewsHooks $hooks;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->hooks = new ViewsHooks();
  }

  /**
   * Tests a row without the langcode property gets the language of the row.
   */
  public function testFillsMissingLangcode(): void {
    $row = new ResultRow(['_entity' => $this->contentEntity('de')]);

    $variables = $this->variables($row);
    $this->hooks->preprocessViewsViewField($variables);

    $this->assertSame('de', $row->node_field_data_langcode);
  }

  /**
   * Tests a langcode property holding NULL is filled in.
   */
  public function testFillsNullLangcode(): void {
    $row = new ResultRow([
      '_entity' => $this->contentEntity('de'),
      'node_field_data_langcode' => NULL,
    ]);

    $variables = $this->variables($row);
    $this->hooks->preprocessViewsViewField($variables);

    $this->assertSame('de', $row->node_field_data_langcode);
  }

  /**
   * Tests a langcode the query filled in is kept.
   */
  public function testKeepsExistingLangcode(): void {
    $row = new ResultRow([
      '_entity' => $this->contentEntity('de'),
      'node_field_data_langcode' => 'es',
    ]);

    $variables = $this->variables($row);
    $this->hooks->preprocessViewsViewField($variables);

    $this->assertSame('es', $row->node_field_data_langcode);
  }

  /**
   * Tests fields whose name is not status are left alone.
   */
  public function testIgnoresOtherFields(): void {
    $row = new ResultRow(['_entity' => $this->contentEntity('de')]);

    $variables = $this->variables($row, 'title');
    $this->hooks->preprocessViewsViewField($variables);

    $this->assertFalse(property_exists($row, 'node_field_data_langcode'));
  }

  /**
   * Tests a row whose entity is not a content entity is left alone.
   */
  public function testIgnoresNonContentEntity(): void {
    $row = new ResultRow(['_entity' => $this->createMock(EntityInterface::class)]);

    $variables = $this->variables($row);
    $this->hooks->preprocessViewsViewField($variables);

    $this->assertFalse(property_exists($row, 'node_field_data_langcode'));
  }

  /**
   * Builds the template variables for a row.
   *
   * @param \Drupal\views\ResultRow $row
   *   The result row.
   * @param string $fieldName
   *   (optional) The name of the views field.
   *
   * @return array<string, mixed>
   *   The template variables.
   */
  protected function variables(ResultRow $row, string $fieldName = 'status'): array {
    return [
      'field' => $this->statusField($fieldName),
      'row' => $row,
    ];
  }

  /**
   * Builds a views field handler with a field name.
   *
   * @param string $fieldName
   *   (optional) The name of the views field.
   *
   * @return \Drupal\views\Plugin\views\field\FieldPluginBase
   *   The field handler.
   */
  protected function statusField(string $fieldName = 'status'): FieldPluginBase {
    $field = $this->createMock(FieldPluginBase::class);
    $field->field = $fieldName;

    return $field;
  }

  /**
   * Builds a content entity with a language.
   *
   * @param string $langcode
   *   The language code.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The entity.
   */
  protected function contentEntity(string $langcode): ContentEntityInterface {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn($langcode);

    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('language')->willReturn($language);

    return $entity;
  }

}
