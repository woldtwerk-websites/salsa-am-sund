<?php

declare(strict_types=1);

namespace Drupal\workspace_site\Theme;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;
use Drupal\user\UserInterface;
use Drupal\workspace_site\Routing\UserFrontendRouteSubscriber;

/**
 * Keeps the admin theme when an admin edits another account.
 *
 * The user edit and cancel routes render in the site theme, so admins can no
 * longer rely on the admin route detection for the admin theme. They still get
 * it when they edit an account other than their own; editing their own account
 * renders in the site theme like for every member.
 */
final class OtherUserAdminThemeNegotiator implements ThemeNegotiatorInterface {

  /**
   * Constructs the theme negotiator.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    if (!in_array($route_match->getRouteName(), UserFrontendRouteSubscriber::ROUTES, TRUE)) {
      return FALSE;
    }

    $account = $route_match->getParameter('user');
    if (!$account instanceof UserInterface) {
      return FALSE;
    }
    if ((string) $account->id() === (string) $this->currentUser->id()) {
      return FALSE;
    }

    return $this->currentUser->hasPermission('view the administration theme');
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    $admin_theme = $this->configFactory->get('system.theme')->get('admin');

    return is_string($admin_theme) && $admin_theme !== '' ? $admin_theme : NULL;
  }

}
