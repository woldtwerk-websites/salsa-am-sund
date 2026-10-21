<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Finds event occurrences by materialized smart date field values.
 */
final class EventOccurrenceRepository {

  /**
   * Constructs an EventOccurrenceRepository.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Finds event occurrences within a time range.
   *
   * Smart Date Recurring materializes recurring dates as field deltas, so one
   * occurrence is created per field delta. A single entity query narrows the
   * candidates; the deltas themselves are filtered and sorted in PHP.
   *
   * @param \Drupal\workspace_events\EventFilter $filter
   *   The filter to apply.
   * @param \DateTimeInterface $from
   *   The start of the time range.
   * @param \DateTimeInterface $to
   *   The end of the time range.
   * @param int|null $limit
   *   The maximum number of occurrences to return, or NULL for no limit.
   *
   * @return \Drupal\workspace_events\EventOccurrence[]
   *   The occurrences, sorted by start date and time.
   */
  public function findOccurrences(EventFilter $filter, \DateTimeInterface $from, \DateTimeInterface $to, ?int $limit = NULL): array {
    $from_timestamp = $from->getTimestamp();
    $to_timestamp = $to->getTimestamp();
    if ($from_timestamp > $to_timestamp) {
      return [];
    }

    $node_storage = $this->entityTypeManager->getStorage('node');
    $query = $node_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('status', NodeInterface::PUBLISHED)
      // Only consider date deltas that overlap the requested range.
      ->condition('field_when.value', $to_timestamp, '<=')
      ->condition('field_when.end_value', $from_timestamp, '>=');

    if ($filter->types !== []) {
      $query->condition('field_event_type.value', $filter->types, 'IN');
    }
    if ($filter->locationIds !== []) {
      $query->condition('field_location.target_id', $filter->locationIds, 'IN');
    }

    $nids = $query->execute();
    if ($nids === []) {
      return [];
    }

    $nodes = $node_storage->loadMultiple($nids);
    $occurrences = [];
    foreach ($nodes as $node) {
      \assert($node instanceof NodeInterface);
      $occurrence = $this->getOccurrencesForNode($node, $from_timestamp, $to_timestamp);
      $occurrences = array_merge($occurrences, $occurrence);
    }

    usort($occurrences, static function (EventOccurrence $a, EventOccurrence $b): int {
      return [
        $a->start->getTimestamp(),
        (int) $a->node->id(),
        $a->delta,
      ] <=> [
        $b->start->getTimestamp(),
        (int) $b->node->id(),
        $b->delta,
      ];
    });

    if ($limit !== NULL) {
      $occurrences = array_slice($occurrences, 0, max(0, $limit));
    }

    return $occurrences;
  }

  /**
   * Returns the occurrences of a node that overlap a range.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   * @param int $from
   *   The start timestamp of the range.
   * @param int $to
   *   The end timestamp of the range.
   *
   * @return \Drupal\workspace_events\EventOccurrence[]
   *   The occurrences.
   */
  private function getOccurrencesForNode(NodeInterface $node, int $from, int $to): array {
    $type = $node->get('field_event_type')->getString();
    if ($type === '') {
      return [];
    }

    $location = $node->get('field_location')->entity;
    if ($location !== NULL && !$location instanceof TermInterface) {
      $location = NULL;
    }

    $occurrences = [];
    foreach ($node->get('field_when') as $delta => $item) {
      $start_timestamp = (int) $item->get('value')->getString();
      if ($start_timestamp === 0) {
        continue;
      }
      $end_timestamp = (int) $item->get('end_value')->getString();
      $duration = (int) $item->get('duration')->getString();
      // An event without duration has no known end; treat its start as end.
      $effective_end = $duration === 0 ? $start_timestamp : $end_timestamp;
      if ($start_timestamp > $to || $effective_end < $from) {
        continue;
      }

      $timezone = $this->getTimeZone($item->get('timezone')->getString());
      $start = (new \DateTimeImmutable('@' . $start_timestamp))->setTimezone($timezone);
      $end = $duration === 0
        ? NULL
        : (new \DateTimeImmutable('@' . $end_timestamp))->setTimezone($timezone);

      $occurrences[] = new EventOccurrence(
        $node,
        (int) $delta,
        $start,
        $end,
        self::isAllDay($start_timestamp, $end_timestamp, $timezone),
        $type,
        $location,
      );
    }

    return $occurrences;
  }

  /**
   * Returns a timezone object for a stored timezone name.
   *
   * @param string $timezone
   *   The timezone name, possibly empty.
   *
   * @return \DateTimeZone
   *   The timezone.
   */
  private function getTimeZone(string $timezone): \DateTimeZone {
    if ($timezone !== '') {
      try {
        return new \DateTimeZone($timezone);
      }
      catch (\Exception) {
        // Fall back to the site timezone below.
      }
    }
    return new \DateTimeZone(date_default_timezone_get());
  }

  /**
   * Determines whether a date value represents a whole day.
   *
   * This mirrors the definition used by Smart Date: the range starts at 00:00
   * and ends at 23:59 in its timezone.
   *
   * @param int $start
   *   The start timestamp.
   * @param int $end
   *   The end timestamp.
   * @param \DateTimeZone $timezone
   *   The timezone of the date value.
   *
   * @return bool
   *   TRUE when the value covers a whole day.
   */
  private static function isAllDay(int $start, int $end, \DateTimeZone $timezone): bool {
    $start_time = (new \DateTimeImmutable('@' . $start))->setTimezone($timezone);
    $end_time = (new \DateTimeImmutable('@' . $end))->setTimezone($timezone);
    return $start_time->format('H:i') === '00:00' && $end_time->format('H:i') === '23:59';
  }

}
