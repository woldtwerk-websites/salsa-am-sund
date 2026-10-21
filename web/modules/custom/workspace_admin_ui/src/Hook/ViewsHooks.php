<?php

declare(strict_types=1);

namespace Drupal\workspace_admin_ui\Hook;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Hook implementations for the views output of the admin UI.
 */
class ViewsHooks {

  /**
   * Implements hook_preprocess_views_view_field().
   *
   * Gin's views-view-field--status.html.twig reads the translation of a row
   * from row.node_field_data_langcode. That is the alias of the langcode
   * column the translation language renderer adds to the query, and it only
   * adds it on a multilingual site; on a monolingual one the property is
   * missing and the template passes NULL to
   * ContentEntityBase::hasTranslation(), which PHP 8.5 deprecates ("Using
   * null as an array offset").
   *
   * The entity of the row already is the row's translation, so its language
   * code is the one the template is after. A row that has the property is
   * left alone: on a multilingual site the query fills it in.
   *
   * @param array<string, mixed> $variables
   *   The template variables. 'field' is the views field handler, 'row' the
   *   result row.
   */
  #[Hook('preprocess_views_view_field')]
  public function preprocessViewsViewField(array &$variables): void {
    // The theme suggestion views_view_field__status comes from the ID of the
    // views field, so this is exactly the set of fields gin's template
    // renders, and 'status' is the field name it reads the state from.
    $field = $variables['field'] ?? NULL;
    if (!$field instanceof FieldPluginBase || $field->field !== 'status') {
      return;
    }

    $row = $variables['row'] ?? NULL;
    if (!$row instanceof ResultRow) {
      return;
    }
    $entity = $row->_entity;
    if (!$entity instanceof ContentEntityInterface) {
      return;
    }
    // isset() covers a missing property and one holding NULL.
    if (isset($row->node_field_data_langcode)) {
      return;
    }

    // Views rows are property bags: the query adds a column as a property of
    // the same name, so the property is not declared on ResultRow.
    // @phpstan-ignore property.notFound
    $row->node_field_data_langcode = $entity->language()->getId();
  }

}
