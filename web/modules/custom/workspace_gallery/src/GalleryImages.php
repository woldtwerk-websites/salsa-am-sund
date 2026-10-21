<?php

declare(strict_types=1);

namespace Drupal\workspace_gallery;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Builds the image data used by the gallery theme templates.
 */
final class GalleryImages {

  /**
   * Constructs the gallery image builder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Builds the images of a gallery node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The gallery node.
   * @param int|null $limit
   *   (optional) The maximum number of images to return. NULL returns all.
   *
   * @return array{images: list<array{src: string, alt: string, width: int, height: int}>, cache: \Drupal\Core\Cache\CacheableMetadata}
   *   The image data and the cache metadata of the loaded media and files.
   */
  public function build(NodeInterface $node, ?int $limit = NULL): array {
    $cache = new CacheableMetadata();
    if (!$node->hasField('field_gallery_images')) {
      return ['images' => [], 'cache' => $cache];
    }

    $media_ids = [];
    foreach ($node->get('field_gallery_images') as $item) {
      $target_id = $item->get('target_id')->getValue();
      if (is_numeric($target_id) && (int) $target_id > 0) {
        $media_ids[] = (int) $target_id;
      }
    }
    if ($media_ids === []) {
      return ['images' => [], 'cache' => $cache];
    }
    if ($limit !== NULL) {
      $media_ids = array_slice($media_ids, 0, max(0, $limit));
    }

    $media_storage = $this->entityTypeManager->getStorage('media');
    $file_storage = $this->entityTypeManager->getStorage('file');
    $media_items = $media_storage->loadMultiple($media_ids);

    // Read alt text and dimensions from the image field item and collect the
    // file IDs first, so all files can be loaded in a single query.
    $rows = [];
    $file_ids = [];
    foreach ($media_ids as $media_id) {
      $media = $media_items[$media_id] ?? NULL;
      if (!$media instanceof MediaInterface || $media->bundle() !== 'image') {
        continue;
      }
      $cache->addCacheableDependency($media);
      $image = $media->get('field_media_image')->first();
      if ($image === NULL) {
        continue;
      }
      $file_id = $image->get('target_id')->getValue();
      if (!is_numeric($file_id) || (int) $file_id <= 0) {
        continue;
      }
      $file_id = (int) $file_id;
      $alt = $image->get('alt')->getValue();
      $width = $image->get('width')->getValue();
      $height = $image->get('height')->getValue();
      $rows[] = [
        'file_id' => $file_id,
        'alt' => is_string($alt) ? $alt : '',
        'width' => is_numeric($width) ? (int) $width : 0,
        'height' => is_numeric($height) ? (int) $height : 0,
      ];
      $file_ids[$file_id] = $file_id;
    }

    if ($rows === []) {
      return ['images' => [], 'cache' => $cache];
    }

    $files = $file_storage->loadMultiple($file_ids);
    $images = [];
    foreach ($rows as $row) {
      $file = $files[$row['file_id']] ?? NULL;
      if (!$file instanceof FileInterface) {
        continue;
      }
      $cache->addCacheableDependency($file);
      $uri = $file->getFileUri();
      if ($uri === NULL || $uri === '') {
        continue;
      }
      $images[] = [
        'src' => $uri,
        'alt' => $row['alt'],
        'width' => $row['width'],
        'height' => $row['height'],
      ];
    }

    return ['images' => $images, 'cache' => $cache];
  }

}
