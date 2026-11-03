<?php

declare(strict_types=1);

namespace Drupal\workspace_site\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;
use Drupal\workspace_site\PendingRegistration;

/**
 * Grants access to the approval and deletion of a pending registration.
 *
 * Administrators manage every account, while the dedicated permission lets
 * editors manage only accounts that registered themselves and still await
 * approval. Both routes and the row operations use this check, so an editor
 * cannot reach other accounts through the operation URLs.
 */
final class RegistrationApprovalAccess {

  /**
   * Constructs the registration approval access check.
   *
   * @param \Drupal\workspace_site\PendingRegistration $pendingRegistration
   *   The pending registration tracker.
   */
  public function __construct(
    private readonly PendingRegistration $pendingRegistration,
  ) {}

  /**
   * Checks access to the approval and deletion of a registration.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account whose access is checked.
   * @param \Drupal\user\UserInterface $user
   *   The registration.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account, UserInterface $user): AccessResultInterface {
    $permission = AccessResult::allowedIfHasPermissions($account, [
      'administer users',
      'approve user registrations',
    ], 'OR');
    $pending = AccessResult::allowedIf($this->pendingRegistration->isPending($user))
      ->addCacheableDependency($user);

    return $permission->andIf($pending);
  }

}
