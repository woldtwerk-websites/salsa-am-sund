<?php

declare(strict_types=1);

namespace Drupal\workspace_admin_ui;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\masquerade\Masquerade;
use Drupal\user\ToolbarLinkBuilder as BaseToolbarLinkBuilder;

/**
 * Adds the Unmasquerade link to the toolbar user menu.
 */
class ToolbarLinkBuilder extends BaseToolbarLinkBuilder {

  /**
   * Constructs the toolbar link builder.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $account
   *   The current user.
   * @param \Drupal\masquerade\Masquerade $masquerade
   *   The masquerade service.
   */
  public function __construct(
    AccountProxyInterface $account,
    protected Masquerade $masquerade,
  ) {
    parent::__construct($account);
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   A renderable array as expected by the renderer service.
   */
  public function renderToolbarLinks(): array {
    /** @var array<string, mixed> $build */
    $build = parent::renderToolbarLinks();

    // The link is only there while masquerading, so the tray varies by it.
    /** @var array{contexts?: list<string>} $cache */
    $cache = $build['#cache'] ?? [];
    $cache['contexts'][] = 'session.is_masquerading';
    $build['#cache'] = $cache;

    if (!$this->masquerade->isMasquerading()) {
      return $build;
    }

    /** @var array<string, array<string, mixed>> $links */
    $links = $build['#links'];
    $reordered = [];
    foreach ($links as $key => $link) {
      // Keep Unmasquerade next to the other account actions, above Log out.
      if ($key === 'logout') {
        $reordered['masquerade_unmasquerade'] = [
          'title' => $this->t('Unmasquerade'),
          'url' => Url::fromRoute('masquerade.unmasquerade'),
          'attributes' => [
            'title' => $this->t('Switch back to your own account'),
          ],
        ];
      }
      $reordered[$key] = $link;
    }
    $build['#links'] = $reordered;

    return $build;
  }

}
