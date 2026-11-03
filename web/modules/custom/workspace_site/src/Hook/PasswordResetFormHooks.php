<?php

declare(strict_types=1);

namespace Drupal\workspace_site\Hook;

use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;
use Drupal\user\UserInterface;
use Drupal\workspace_site\PasswordResetDetector;

/**
 * Hook implementations for the password reset form.
 *
 * Right after a one-time login, the account edit form only asks for the new
 * password. The form mode and form display live in configuration; these hooks
 * switch to them and hide the remaining account fields.
 */
final class PasswordResetFormHooks {

  /**
   * Constructs the password reset form hooks.
   *
   * @param \Drupal\workspace_site\PasswordResetDetector $passwordResetDetector
   *   The password reset detector.
   */
  public function __construct(
    private readonly PasswordResetDetector $passwordResetDetector,
  ) {}

  /**
   * Implements hook_entity_form_mode_alter().
   *
   * @param string $form_mode
   *   The form mode, passed by reference.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity the form is built for.
   */
  #[Hook('entity_form_mode_alter')]
  public function entityFormModeAlter(string &$form_mode, EntityInterface $entity): void {
    if ($form_mode === 'default' && $entity instanceof UserInterface && $this->passwordResetDetector->isPasswordReset()) {
      $form_mode = 'password_reset';
    }
  }

  /**
   * Implements hook_form_user_form_alter().
   *
   * @param array<mixed> $form
   *   The account form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   *
   * @param-out array<mixed> $form
   */
  #[Hook('form_user_form_alter')]
  public function formUserFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $form_display = $form_state->get('form_display');
    if (!$form_display instanceof EntityFormDisplayInterface || $form_display->getMode() !== 'password_reset') {
      return;
    }

    $form['#salsa_password_reset'] = TRUE;

    $account = $form['account'] ?? NULL;
    if (!is_array($account)) {
      return;
    }

    // Keep the password fields and their default values. The other account
    // fields stay in the form, so their submitted/default values are kept, but
    // they are not rendered. Fields with their own widget (first and last name,
    // language, path, timezone, picture) are already removed by the form
    // display itself; current_pass is hidden by core for this case.
    foreach (Element::children($account) as $key) {
      if (!is_string($key) && !is_int($key)) {
        continue;
      }
      if ($key === 'pass' || !is_array($account[$key] ?? NULL)) {
        continue;
      }
      $account[$key]['#access'] = FALSE;
    }

    $form['account'] = $account;
  }

}
