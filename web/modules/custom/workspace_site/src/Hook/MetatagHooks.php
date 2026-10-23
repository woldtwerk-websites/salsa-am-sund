<?php

declare(strict_types=1);

namespace Drupal\workspace_site\Hook;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Alters the meta tags of Canvas pages.
 */
final class MetatagHooks {

  /**
   * Constructs the meta tag hooks.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_metatags_alter().
   *
   * Canvas defaults the canonical URL to the relative page URL, which search
   * engines reject. Make it absolute unless an editor changed the value.
   *
   * @param array<string, mixed> $metatags
   *   The meta tags for the current route.
   * @param array<string, mixed> $context
   *   The context of the meta tags, including the route entity.
   */
  #[Hook('metatags_alter')]
  public function alterMetatags(array &$metatags, array &$context): void {
    if (($metatags['canonical_url'] ?? NULL) !== '[canvas_page:url]') {
      return;
    }
    $entity = $context['entity'] ?? NULL;
    if (!$entity instanceof Page) {
      return;
    }

    // The front page is also reachable through its alias. Both paths must
    // share the same canonical URL, so the front page uses the site URL.
    $front = $this->configFactory->get('system.site')->get('page.front');
    try {
      $url = $entity->toUrl();
      $paths = [$url->toString(), '/' . $url->getInternalPath()];
    }
    catch (\Exception) {
      // Without a canonical URL, fall back to the absolute page URL.
      $paths = [];
    }
    $metatags['canonical_url'] = \in_array($front, $paths, TRUE)
      ? '[site:url]'
      : '[canvas_page:url:absolute]';
  }

}
