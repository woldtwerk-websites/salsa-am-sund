<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

/**
 * Looks up the fixed organizer terms.
 */
interface OrganizerLookupInterface {

  /**
   * Returns the term ID of a fixed organizer term.
   *
   * @param string $uuid
   *   The term UUID, one of the constants of OrganizerResolver.
   *
   * @return int|null
   *   The term ID, or NULL when the term does not exist.
   */
  public function id(string $uuid): ?int;

  /**
   * Returns the term IDs keyed by UUID.
   *
   * @return array<string, int>
   *   The term IDs of the existing fixed terms.
   */
  public function ids(): array;

}
