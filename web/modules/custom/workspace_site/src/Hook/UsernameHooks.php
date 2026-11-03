<?php

declare(strict_types=1);

namespace Drupal\workspace_site\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\user\UserInterface;
use Drupal\workspace_site\UsernameGenerator;

/**
 * Keeps the user name in sync with the first and last name.
 */
final class UsernameHooks {

  /**
   * Constructs the user name hooks.
   *
   * @param \Drupal\workspace_site\UsernameGenerator $usernameGenerator
   *   The user name generator.
   */
  public function __construct(
    private readonly UsernameGenerator $usernameGenerator,
  ) {}

  /**
   * Implements hook_form_user_register_form_alter().
   *
   * Replaces the user name input with a placeholder. The real user name is
   * generated from the first and last name when the user is saved.
   *
   * @param array<string, mixed> $form
   *   The register form.
   */
  #[Hook('form_user_register_form_alter')]
  public function alterRegisterForm(array &$form): void {
    $account = $form['account'] ?? [];
    if (!\is_array($account) || !isset($account['name'])) {
      return;
    }

    $account['name'] = [
      '#type' => 'value',
      '#value' => $this->usernameGenerator->generate('', ''),
    ];
    $form['account'] = $account;
  }

  /**
   * Implements hook_user_presave().
   *
   * @param \Drupal\user\UserInterface $user
   *   The user being saved.
   */
  #[Hook('user_presave')]
  public function presave(UserInterface $user): void {
    if (!$user->hasField('field_first_name') || !$user->hasField('field_last_name')) {
      return;
    }

    $first_name = trim($user->get('field_first_name')->getString());
    $last_name = trim($user->get('field_last_name')->getString());
    if ($first_name === '' && $last_name === '') {
      return;
    }

    if (!$user->isNew()) {
      $original = $user->getOriginal();
      if ($original instanceof UserInterface) {
        $original_first_name = $original->hasField('field_first_name')
          ? trim($original->get('field_first_name')->getString())
          : '';
        $original_last_name = $original->hasField('field_last_name')
          ? trim($original->get('field_last_name')->getString())
          : '';
        if ($first_name === $original_first_name && $last_name === $original_last_name) {
          return;
        }
      }
    }

    $exclude_uid = $user->isNew() ? NULL : (int) $user->id();
    $name = $this->usernameGenerator->generate($first_name, $last_name, $exclude_uid);
    if ($name !== $user->getAccountName()) {
      $user->setUsername($name);
    }
  }

}
