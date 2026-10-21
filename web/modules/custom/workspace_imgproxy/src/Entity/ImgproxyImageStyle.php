<?php

declare(strict_types=1);

namespace Drupal\workspace_imgproxy\Entity;

use Drupal\workspace_imgproxy\StyleOptions;
use Drupal\workspace_imgproxy\UrlBuilder;
use Drupal\image\Entity\ImageStyle;

/**
 * An image style that is served by imgproxy.
 *
 * Only the URL changes: the style, its effects and the dimensions it reports
 * stay the ones Drupal has, so everything that reads an image style — the
 * responsive image styles, the field formatters, the media library — keeps
 * working, while no derivative is ever written to disk.
 *
 * Swapped in for the core class by
 * \Drupal\workspace_imgproxy\Hook\ImgproxyHooks::entityTypeAlter(). The
 * services cannot be injected: an entity is built by its storage, not by the
 * container, which is why core reads its own services the same way here.
 */
class ImgproxyImageStyle extends ImageStyle {

  /**
   * {@inheritdoc}
   */
  public function buildUrl($path, $clean_urls = NULL) {
    /** @var \Drupal\workspace_imgproxy\UrlBuilder $urlBuilder */
    $urlBuilder = \Drupal::service(UrlBuilder::class);
    /** @var \Drupal\workspace_imgproxy\StyleOptions $styleOptions */
    $styleOptions = \Drupal::service(StyleOptions::class);

    // A URI imgproxy has no access to, or an environment without an imgproxy
    // instance, falls back to a derivative Drupal renders itself.
    return $urlBuilder->build($path, $styleOptions->forStyle($this))
      ?? parent::buildUrl($path, $clean_urls);
  }

}
