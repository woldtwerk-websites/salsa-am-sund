<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

/**
 * Converts stored block settings of the event types era.
 *
 * Blocks and Canvas component trees used to store the keys of
 * \Drupal\workspace_events\EventFilter::TYPES. They now store organizer term
 * IDs and a course flag. The conversion is a pure function, so the data script
 * and the tests share it.
 */
final class BlockSettingsMigration {

  /**
   * Converts legacy type keys into the organizer and course block settings.
   *
   * Unknown keys are ignored. Duplicate organizers are removed, the order
   * follows the order of $organizerIds so the result is stable.
   *
   * @param array<int|string, mixed> $types
   *   The stored type keys, for example ['party', 'external_party'].
   * @param array<string, int> $organizerIds
   *   The organizer term IDs, keyed by legacy type key.
   *
   * @return array{organizers: int[], courses: bool}
   *   The new block settings.
   */
  public static function convertTypes(array $types, array $organizerIds): array {
    $selected = [];
    $courses = FALSE;
    foreach ($types as $type) {
      if (!is_string($type)) {
        continue;
      }
      $type = trim($type);
      if ($type === 'course') {
        $courses = TRUE;
        continue;
      }
      $organizer_id = $organizerIds[$type] ?? NULL;
      if ($organizer_id !== NULL && $organizer_id > 0 && !in_array($organizer_id, $selected, TRUE)) {
        $selected[] = $organizer_id;
      }
    }

    return [
      'organizers' => $selected,
      'courses' => $courses,
    ];
  }

}
