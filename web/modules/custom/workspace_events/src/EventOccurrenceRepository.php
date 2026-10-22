<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemInterface;
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
      // Events and courses are separate bundles.
      ->condition('type', ['course', 'event'], 'IN')
      ->condition('status', NodeInterface::PUBLISHED)
      // Only consider date deltas that overlap the requested range.
      ->condition('field_when.value', $to_timestamp, '<=')
      ->condition('field_when.end_value', $from_timestamp, '>=');

    $type_group = $this->buildTypeCondition($query, $filter);
    if ($type_group !== NULL) {
      $query->condition($type_group);
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
   * Finds one occurrence of a node by its date delta.
   *
   * The node is expected to be loaded already, so the method reads its date
   * field and the referenced organizer and location terms.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event or course node.
   * @param int $delta
   *   The delta of the smart date field value.
   *
   * @return \Drupal\workspace_events\EventOccurrence|null
   *   The occurrence, or NULL when the node is of another bundle or has no
   *   such date.
   */
  public function findOccurrence(NodeInterface $node, int $delta): ?EventOccurrence {
    if (!in_array($node->bundle(), ['event', 'course'], TRUE) || !$node->hasField('field_when')) {
      return NULL;
    }
    $item = $node->get('field_when')->get($delta);
    if (!$item instanceof FieldItemInterface) {
      return NULL;
    }

    return $this->buildOccurrence(
      $node,
      $delta,
      $item,
      $this->getOrganizer($node),
      $this->getLocation($node),
    );
  }

  /**
   * Builds the type condition group of the candidate query.
   *
   * Events are selected by their organizer term and node ID, courses by their
   * bundle. Both cases are combined in a single OR group, so the occurrences
   * of all requested organizers, events and courses are found with one entity
   * query.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The entity query that the condition group belongs to.
   * @param \Drupal\workspace_events\EventFilter $filter
   *   The filter to apply.
   *
   * @return \Drupal\Core\Entity\Query\ConditionInterface|null
   *   The condition group, or NULL when every event and course is included.
   */
  private function buildTypeCondition(QueryInterface $query, EventFilter $filter): ?ConditionInterface {
    $unrestricted = $filter->organizerIds === []
      && $filter->courseIds === []
      && $filter->eventIds === [];
    if ($filter->includeEvents && $filter->includeCourses && $unrestricted) {
      return NULL;
    }

    $group = $query->orConditionGroup();
    if ($filter->includeCourses) {
      $course_group = $query->andConditionGroup()->condition('type', 'course');
      if ($filter->courseIds !== []) {
        $course_group->condition('nid', $filter->courseIds, 'IN');
      }
      $group->condition($course_group);
    }
    if ($filter->includeEvents) {
      $event_group = $query->andConditionGroup()->condition('type', 'event');
      if ($filter->organizerIds !== []) {
        $event_group->condition('field_organizer.target_id', $filter->organizerIds, 'IN');
      }
      if ($filter->eventIds !== []) {
        $event_group->condition('nid', $filter->eventIds, 'IN');
      }
      $group->condition($event_group);
    }
    if ($group->count() === 0) {
      // No kind of node was requested: match nothing instead of everything.
      $group->condition('type', '');
    }

    return $group;
  }

  /**
   * Returns the occurrences of a node that overlap a range.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event or course node.
   * @param int $from
   *   The start timestamp of the range.
   * @param int $to
   *   The end timestamp of the range.
   *
   * @return \Drupal\workspace_events\EventOccurrence[]
   *   The occurrences.
   */
  private function getOccurrencesForNode(NodeInterface $node, int $from, int $to): array {
    $organizer = $this->getOrganizer($node);
    $location = $this->getLocation($node);

    $occurrences = [];
    foreach ($node->get('field_when') as $delta => $item) {
      $occurrence = $this->buildOccurrence($node, (int) $delta, $item, $organizer, $location);
      if ($occurrence === NULL) {
        continue;
      }
      if ($occurrence->start->getTimestamp() > $to || $occurrence->endTimestamp() < $from) {
        continue;
      }
      $occurrences[] = $occurrence;
    }

    return $occurrences;
  }

  /**
   * Builds an occurrence from one date value of a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event or course node.
   * @param int $delta
   *   The delta of the smart date field value.
   * @param \Drupal\Core\Field\FieldItemInterface $item
   *   The date value.
   * @param \Drupal\taxonomy\TermInterface|null $organizer
   *   The organizer term, if any.
   * @param \Drupal\taxonomy\TermInterface|null $location
   *   The location term, if any.
   *
   * @return \Drupal\workspace_events\EventOccurrence|null
   *   The occurrence, or NULL when the date value is empty.
   */
  private function buildOccurrence(NodeInterface $node, int $delta, FieldItemInterface $item, ?TermInterface $organizer, ?TermInterface $location): ?EventOccurrence {
    $start_timestamp = (int) $item->get('value')->getString();
    if ($start_timestamp === 0) {
      return NULL;
    }
    $end_timestamp = (int) $item->get('end_value')->getString();
    $duration = (int) $item->get('duration')->getString();
    $timezone = $this->getTimeZone($item->get('timezone')->getString());

    return new EventOccurrence(
      $node,
      $delta,
      (new \DateTimeImmutable('@' . $start_timestamp))->setTimezone($timezone),
      // An event without duration has no known end.
      $duration === 0
        ? NULL
        : (new \DateTimeImmutable('@' . $end_timestamp))->setTimezone($timezone),
      self::isAllDay($start_timestamp, $end_timestamp, $timezone),
      $organizer,
      $location,
    );
  }

  /**
   * Returns the organizer term of a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event or course node.
   *
   * @return \Drupal\taxonomy\TermInterface|null
   *   The organizer, or NULL when the node has none.
   */
  private function getOrganizer(NodeInterface $node): ?TermInterface {
    if (!$node->hasField('field_organizer')) {
      return NULL;
    }
    $organizer = $node->get('field_organizer')->entity;
    return $organizer instanceof TermInterface ? $organizer : NULL;
  }

  /**
   * Returns the location term of a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event or course node.
   *
   * @return \Drupal\taxonomy\TermInterface|null
   *   The location, or NULL when the node has none.
   */
  private function getLocation(NodeInterface $node): ?TermInterface {
    if (!$node->hasField('field_location')) {
      return NULL;
    }
    $location = $node->get('field_location')->entity;
    return $location instanceof TermInterface ? $location : NULL;
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
