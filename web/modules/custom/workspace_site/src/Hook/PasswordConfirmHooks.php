<?php

declare(strict_types=1);

namespace Drupal\workspace_site\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for the password confirm form element.
 *
 * The theme shows form descriptions in an info popover next to the field
 * label. A password_confirm element has no visible label of its own: its two
 * password fields carry the labels, so the description would stay a plain text
 * block below both fields.
 */
final class PasswordConfirmHooks {

  /**
   * Implements hook_element_info_alter().
   *
   * Runs the description move after the process callback of the element built
   * the two password fields.
   *
   * @param array<string, mixed> $info
   *   The element information, keyed by element type.
   */
  #[Hook('element_info_alter')]
  public function elementInfoAlter(array &$info): void {
    $element_info = $info['password_confirm'] ?? [];
    $element_info = is_array($element_info) ? $element_info : [];
    $after_build = $element_info['#after_build'] ?? [];
    $after_build = is_array($after_build) ? $after_build : [];
    $after_build[] = [self::class, 'moveDescription'];
    $element_info['#after_build'] = $after_build;
    $info['password_confirm'] = $element_info;
  }

  /**
   * After-build callback: moves the description to the first password field.
   *
   * The label of that field ("Password") then carries the info popover in the
   * theme, like the label of a regular field does.
   *
   * @param array<mixed> $element
   *   The password confirm element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<mixed>
   *   The altered password confirm element.
   */
  public static function moveDescription(array $element, FormStateInterface $form_state): array {
    $pass1 = $element['pass1'] ?? NULL;
    if (!is_array($pass1) || empty($element['#description']) || !empty($pass1['#description'])) {
      return $element;
    }

    $pass1['#description'] = $element['#description'];
    if (isset($element['#description_display'])) {
      $pass1['#description_display'] = $element['#description_display'];
    }
    unset($element['#description'], $element['#description_display']);
    $element['pass1'] = $pass1;

    return $element;
  }

}
