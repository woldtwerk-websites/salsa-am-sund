<?php

declare(strict_types=1);

namespace Drupal\workspace_site;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;

/**
 * Knows which accounts were created by an OpenID Connect provider.
 *
 * For these accounts the provider is the source of the email address: it is
 * synchronized on every login. Accounts that registered through the form and
 * connected a provider later keep their own address.
 */
final class SsoAccount {

  /**
   * The user data key that holds the ID of the creating client.
   */
  public const DATA_NAME = 'sso_provider';

  /**
   * The module name of the user data.
   */
  private const MODULE = 'workspace_site';

  /**
   * Constructs the SSO account helper.
   *
   * @param \Drupal\user\UserDataInterface $userData
   *   The user data service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Component\Utility\EmailValidatorInterface $emailValidator
   *   The email validator.
   */
  public function __construct(
    private readonly UserDataInterface $userData,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EmailValidatorInterface $emailValidator,
  ) {}

  /**
   * Marks an account as created by an OpenID Connect client.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account.
   * @param string $client_id
   *   The ID of the OpenID Connect client entity.
   */
  public function markCreatedBy(UserInterface $account, string $client_id): void {
    if ($account->id() === NULL || $client_id === '') {
      return;
    }
    $this->userData->set(self::MODULE, (int) $account->id(), self::DATA_NAME, $client_id);
  }

  /**
   * Returns the client that created the account.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account.
   *
   * @return string|null
   *   The client entity ID, or NULL when the account was not created by a
   *   provider.
   */
  public function getCreatingProvider(UserInterface $account): ?string {
    if ($account->id() === NULL) {
      return NULL;
    }
    $provider = $this->userData->get(self::MODULE, (int) $account->id(), self::DATA_NAME);

    return is_string($provider) && $provider !== '' ? $provider : NULL;
  }

  /**
   * Synchronizes the email address of an account with its provider.
   *
   * The address is only applied when the account was created by exactly this
   * client, the provider sends a valid address that differs from the current
   * one and no other account uses it. The caller saves the account.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account.
   * @param string $client_id
   *   The ID of the OpenID Connect client entity that handled the login.
   * @param mixed $email
   *   The email address claim of the provider.
   *
   * @return bool
   *   TRUE when the account's email address was changed.
   */
  public function syncMail(UserInterface $account, string $client_id, mixed $email): bool {
    if ($this->getCreatingProvider($account) !== $client_id) {
      return FALSE;
    }
    if (!is_string($email)) {
      return FALSE;
    }
    $email = trim($email);
    if ($email === '' || !$this->emailValidator->isValid($email)) {
      return FALSE;
    }
    if ($email === $account->getEmail()) {
      return FALSE;
    }

    // Never take an address that belongs to another account.
    $accounts = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['mail' => $email]);
    foreach ($accounts as $other) {
      if ($other instanceof UserInterface && $other->id() !== $account->id()) {
        return FALSE;
      }
    }

    $account->setEmail($email);

    return TRUE;
  }

}
