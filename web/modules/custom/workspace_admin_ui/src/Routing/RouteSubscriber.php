<?php

declare(strict_types=1);

namespace Drupal\workspace_admin_ui\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Adds the masquerade target access check to masquerade's switch route.
 *
 * Without it the route has no access check a menu link tree can evaluate: its
 * only requirement is the CSRF token, which needs the incoming request, so
 * every link to it is dropped from the tree as inaccessible.
 *
 * @see \Drupal\workspace_admin_ui\Access\MasqueradeTargetAccessCheck
 */
final class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('entity.user.masquerade');
    if ($route === NULL) {
      return;
    }

    $route->addRequirements([
      '_workspace_admin_ui_masquerade_target_access' => 'TRUE',
    ]);
  }

}
