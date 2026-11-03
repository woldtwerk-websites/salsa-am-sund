<?php

declare(strict_types=1);

namespace Drupal\workspace_site\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\workspace_site\PendingRegistration;

/**
 * Grants access to the registration form of a pending OpenID Connect account.
 */
final class PendingRegistrationAccess {

  /**
   * Constructs the access checker.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\workspace_site\PendingRegistration $pendingRegistration
   *   The pending registration tracker.
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly PendingRegistration $pendingRegistration,
  ) {}

  /**
   * Checks access to the registration completion form.
   *
   * Only an anonymous visitor with a marked registration may use the form.
   * The result depends on the session, so it must not be cached.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(): AccessResultInterface {
    $allowed = $this->currentUser->isAnonymous()
      && $this->pendingRegistration->getAccount() !== NULL;

    return AccessResult::allowedIf($allowed)->setCacheMaxAge(0);
  }

}
