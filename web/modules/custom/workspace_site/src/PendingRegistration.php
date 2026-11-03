<?php

declare(strict_types=1);

namespace Drupal\workspace_site;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Tracks the pending registration of a new OpenID Connect account.
 *
 * A new account created by an OpenID Connect login is blocked until an
 * administrator approves it. When the provider did not send a usable name, the
 * member is asked for it before the approval, so the account does not end up in
 * the approval queue with a login name as its display name.
 */
final class PendingRegistration {

  /**
   * The session key that holds the user ID of the pending registration.
   */
  public const SESSION_KEY = 'workspace_site.pending_registration_uid';

  /**
   * Constructs the pending registration tracker.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Marks an account as a pending registration.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account.
   */
  public function mark(UserInterface $account): void {
    $session = $this->session();
    if ($session === NULL || $account->id() === NULL) {
      return;
    }
    $session->set(self::SESSION_KEY, (int) $account->id());
  }

  /**
   * Returns the marked account.
   *
   * Only a blocked account that has never logged in counts as a pending
   * registration. Once an administrator activates it, the marked account is
   * ignored.
   *
   * @return \Drupal\user\UserInterface|null
   *   The marked account, or NULL when there is none.
   */
  public function getAccount(): ?UserInterface {
    $session = $this->session();
    if ($session === NULL) {
      return NULL;
    }
    $uid = $session->get(self::SESSION_KEY);
    if (!is_int($uid) && !is_numeric($uid)) {
      return NULL;
    }

    $account = $this->entityTypeManager->getStorage('user')->load((int) $uid);
    if (!$account instanceof UserInterface) {
      return NULL;
    }
    if (!$account->isBlocked() || (int) $account->getLastLoginTime() !== 0) {
      return NULL;
    }
    if (!$account->hasField('field_first_name') || !$account->hasField('field_last_name')) {
      return NULL;
    }

    return $account;
  }

  /**
   * Removes the marked account from the session.
   */
  public function clear(): void {
    $this->session()?->remove(self::SESSION_KEY);
  }

  /**
   * Determines whether one of the account's names is still empty.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account.
   *
   * @return bool
   *   TRUE when the account carries both name fields and one of them is empty.
   */
  public function needsName(UserInterface $account): bool {
    if (!$account->hasField('field_first_name') || !$account->hasField('field_last_name')) {
      return FALSE;
    }

    return trim($account->get('field_first_name')->getString()) === ''
      || trim($account->get('field_last_name')->getString()) === '';
  }

  /**
   * Returns the session of the current request.
   *
   * @return \Symfony\Component\HttpFoundation\Session\SessionInterface|null
   *   The session, or NULL when there is no request with a session.
   */
  private function session(): ?SessionInterface {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL || !$request->hasSession()) {
      return NULL;
    }

    return $request->getSession();
  }

}
