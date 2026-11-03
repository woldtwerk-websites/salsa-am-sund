<?php

declare(strict_types=1);

namespace Drupal\workspace_site;

use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;

/**
 * Sends the pending approval mail to a new account once.
 *
 * OpenID Connect creates new accounts without core's RegisterForm, so core
 * never sends the "pending approval" mail for them. The completion form and the
 * login hook may both handle the same account, so the sent state is stored in
 * the user data and checked before sending.
 */
final class PendingApprovalMailer {

  /**
   * The module name of the user data.
   */
  private const MODULE = 'workspace_site';

  /**
   * The user data key that marks the mail as sent.
   */
  private const DATA_KEY = 'pending_mail_sent';

  /**
   * Constructs the pending approval mailer.
   *
   * @param \Drupal\user\UserDataInterface $userData
   *   The user data service.
   */
  public function __construct(
    private readonly UserDataInterface $userData,
  ) {}

  /**
   * Sends the pending approval mail when it was not sent before.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account that awaits approval.
   *
   * @return bool
   *   TRUE when the mail was sent, FALSE when it was sent before or the
   *   account has no ID.
   */
  public function sendOnce(UserInterface $account): bool {
    if ($account->id() === NULL) {
      return FALSE;
    }
    $uid = (int) $account->id();
    if (!empty($this->userData->get(self::MODULE, $uid, self::DATA_KEY))) {
      return FALSE;
    }

    // Sends the message to the account and, via its sub-mail, to the site mail.
    _user_mail_notify('register_pending_approval', $account);
    $this->userData->set(self::MODULE, $uid, self::DATA_KEY, TRUE);

    return TRUE;
  }

}
