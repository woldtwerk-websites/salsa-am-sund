<?php

declare(strict_types=1);

namespace Drupal\workspace_imgproxy\Hook;

use Drupal\workspace_imgproxy\Entity\ImgproxyImageStyle;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Puts imgproxy in front of every image style.
 */
class ImgproxyHooks {

  /**
   * Implements hook_entity_type_alter().
   *
   * @param array<string, \Drupal\Core\Entity\EntityTypeInterface> $entity_types
   *   The entity type definitions.
   */
  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array &$entity_types): void {
    if (isset($entity_types['image_style'])) {
      $entity_types['image_style']->setClass(ImgproxyImageStyle::class);
    }
  }

}
