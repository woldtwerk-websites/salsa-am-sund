<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Hook;

use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Form hooks for the event content type.
 */
final class EventFormHooks {

  /**
   * Implements hook_form_alter().
   *
   * Shows the level field for courses only.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof ContentEntityFormInterface) {
      return;
    }
    $entity = $form_object->getEntity();
    if ($entity->getEntityTypeId() !== 'node' || $entity->bundle() !== 'event') {
      return;
    }
    if (!isset($form['field_level']) || !is_array($form['field_level'])) {
      return;
    }
    $form['field_level']['#states'] = [
      'visible' => [
        ':input[name="field_event_type"][value="course"]' => ['checked' => TRUE],
      ],
    ];
  }

}
