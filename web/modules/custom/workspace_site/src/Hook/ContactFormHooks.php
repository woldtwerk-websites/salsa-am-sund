<?php

declare(strict_types=1);

namespace Drupal\workspace_site\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for contact message forms.
 *
 * Contact message forms are sent right away, so the preview step is not used
 * on this site and its button is removed.
 */
final class ContactFormHooks {

  /**
   * Implements hook_form_contact_message_form_alter().
   *
   * @param array<mixed> $form
   *   The contact message form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   *
   * @param-out array<mixed> $form
   */
  #[Hook('form_contact_message_form_alter')]
  public function formContactMessageFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $actions = $form['actions'] ?? NULL;
    $preview = is_array($actions) ? ($actions['preview'] ?? NULL) : NULL;
    if (!is_array($preview)) {
      return;
    }

    $preview['#access'] = FALSE;
    $actions['preview'] = $preview;
    $form['actions'] = $actions;
  }

}
