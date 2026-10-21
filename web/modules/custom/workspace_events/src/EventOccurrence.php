<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Immutable value object for a single event occurrence.
 */
final readonly class EventOccurrence {

  /**
   * Constructs an EventOccurrence.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   * @param int $delta
   *   The delta of the smart date field value.
   * @param \DateTimeImmutable $start
   *   The start date and time, in the timezone of the date value.
   * @param \DateTimeImmutable|null $end
   *   The end date and time, or NULL when the event has no known end.
   * @param bool $allDay
   *   Whether the occurrence lasts the whole day.
   * @param string $type
   *   The event type key.
   * @param \Drupal\taxonomy\TermInterface|null $location
   *   The location term, if any.
   */
  public function __construct(
    public NodeInterface $node,
    public int $delta,
    public \DateTimeImmutable $start,
    public ?\DateTimeImmutable $end,
    public bool $allDay,
    public string $type,
    public ?TermInterface $location,
  ) {}

  /**
   * Returns the timestamp at which the occurrence ends.
   *
   * For occurrences without a known end the start is used.
   */
  public function endTimestamp(): int {
    return $this->end?->getTimestamp() ?? $this->start->getTimestamp();
  }

}
