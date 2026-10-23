<?php

declare(strict_types=1);

namespace Drupal\workspace_admin_ui;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;

/**
 * Finds the accounts the masquerade shortcuts point at.
 *
 * The toolbar tab and the Navigation user popover both offer the same two
 * accounts, so the lookup, the access check and the cache tags live here once
 * rather than in each of them. An account is looked up by name: a uid is a
 * fact about one database, while the names are the same everywhere the
 * accounts were seeded.
 */
final class MasqueradeLinks {

  /**
   * The account behind the User shortcut.
   */
  public const USER = 'user';

  /**
   * The account behind the Editor shortcut.
   */
  public const EDITOR = 'editor';

  /**
   * The names of the shortcut accounts, in the order they are offered.
   */
  private const ACCOUNTS = [self::USER, self::EDITOR];

  /**
   * The accounts found so far, keyed by name.
   *
   * Both shortcuts are looked up several times per request — once for the
   * toolbar, once for every menu link tree and again for the access check — so
   * the lookup is remembered here. The service lives for one request only.
   *
   * @var array<string, \Drupal\user\UserInterface|null>
   */
  private array $accounts = [];

  /**
   * Constructs the masquerade link helper.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Loads the account with a user name.
   *
   * @param string $name
   *   The user name.
   *
   * @return \Drupal\user\UserInterface|null
   *   The account, or NULL when no account has the name.
   */
  public function account(string $name): ?UserInterface {
    if (!array_key_exists($name, $this->accounts)) {
      $accounts = $this->entityTypeManager->getStorage('user')
        ->loadByProperties(['name' => $name]);
      /** @var \Drupal\user\UserInterface|null $account */
      $account = reset($accounts) ?: NULL;
      $this->accounts[$name] = $account;
    }

    return $this->accounts[$name];
  }

  /**
   * The accounts the current user may masquerade as.
   *
   * @return array<string, \Drupal\user\UserInterface>
   *   The accounts, keyed by user name.
   */
  public function available(): array {
    $available = [];
    foreach (self::ACCOUNTS as $name) {
      $account = $this->account($name);
      if ($account !== NULL && $this->isAvailable($account)) {
        $available[$name] = $account;
      }
    }

    return $available;
  }

  /**
   * Whether the current user may masquerade as an account.
   *
   * @param \Drupal\user\UserInterface $account
   *   The target account.
   *
   * @return bool
   *   TRUE when masquerade allows the switch.
   */
  public function isAvailable(UserInterface $account): bool {
    // Masquerade's own check covers a missing account, a running masquerade,
    // the target being the current user, and the masquerade permissions.
    return masquerade_target_user_access($account);
  }

  /**
   * The cache tags the shortcuts depend on.
   *
   * @return list<string>
   *   The `user_list` tag, so a link appears when an account is created under
   *   one of the names, and the tags of the accounts that exist.
   */
  public function cacheTags(): array {
    $tags = ['user_list'];
    foreach (self::ACCOUNTS as $name) {
      $account = $this->account($name);
      if ($account !== NULL) {
        $tags = Cache::mergeTags($tags, $account->getCacheTags());
      }
    }

    return $tags;
  }

}
