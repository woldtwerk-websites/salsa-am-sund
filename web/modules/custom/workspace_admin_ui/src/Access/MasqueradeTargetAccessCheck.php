<?php

declare(strict_types=1);

namespace Drupal\workspace_admin_ui\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\user\UserInterface;
use Drupal\workspace_admin_ui\MasqueradeLinks;

/**
 * Checks whether the current user may masquerade as the target account.
 *
 * Masquerade's own switch route only requires a CSRF token; the controller
 * validates the target afterwards. That makes the route unusable for a menu
 * link: the token check needs the incoming request, so a menu tree cannot
 * evaluate it and drops the link as inaccessible. This check is added to the
 * route as a requirement, so both places agree — the menu tree decides whether
 * to show the link, and a request without permission is refused before the
 * controller runs.
 */
final class MasqueradeTargetAccessCheck implements AccessInterface {

  /**
   * Constructs the access check.
   *
   * @param \Drupal\workspace_admin_ui\MasqueradeLinks $masqueradeLinks
   *   The masquerade link helper.
   */
  public function __construct(
    private readonly MasqueradeLinks $masqueradeLinks,
  ) {
  }

  /**
   * Checks access to masquerading as an account.
   *
   * @param \Drupal\user\UserInterface $user
   *   The target account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(UserInterface $user): AccessResultInterface {
    return AccessResult::allowedIf($this->masqueradeLinks->isAvailable($user))
      ->addCacheContexts(['session.is_masquerading', 'user.permissions'])
      // The answer changes when the target account is edited or deleted.
      ->addCacheableDependency($user);
  }

}
