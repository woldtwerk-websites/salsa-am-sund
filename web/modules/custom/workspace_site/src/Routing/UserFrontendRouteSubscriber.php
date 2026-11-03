<?php

declare(strict_types=1);

namespace Drupal\workspace_site\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\workspace_site\Controller\UserTitleController;
use Symfony\Component\Routing\RouteCollection;

/**
 * Renders the user account routes in the site theme.
 *
 * Members edit their own account in the site theme with the Canvas page
 * chrome (header, footer and page title). Canvas skips admin routes, so the
 * `_admin_route` option has to go. Admins editing another account keep the
 * admin theme through OtherUserAdminThemeNegotiator.
 *
 * The account edit form also gets its own page title: members see "Edit
 * profile" for their own account, while admins editing another account keep
 * the account name from the core user title.
 *
 * @see \Drupal\workspace_site\Theme\OtherUserAdminThemeNegotiator
 * @see \Drupal\workspace_site\Controller\UserTitleController
 */
final class UserFrontendRouteSubscriber extends RouteSubscriberBase {

  /**
   * The user routes that render in the site theme.
   *
   * @var string[]
   */
  public const ROUTES = [
    'entity.user.edit_form',
    'entity.user.cancel_form',
  ];

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach (self::ROUTES as $route_name) {
      $route = $collection->get($route_name);
      if ($route === NULL) {
        continue;
      }
      $route->setOption('_admin_route', FALSE);
    }

    // Own account and other account need different titles.
    $edit_route = $collection->get('entity.user.edit_form');
    if ($edit_route !== NULL) {
      $edit_route->setDefault('_title_callback', UserTitleController::class . '::editTitle');
    }
  }

}
