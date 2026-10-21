<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

/**
 * Immutable filter for event occurrences.
 *
 * An occurrence comes from either an event node or a course node. The filter
 * selects both kinds independently, so the selections are combined with OR:
 * a filter that includes events of one organizer and a single course returns
 * that organizer's events and that course.
 *
 * The fields are deliberately explicit instead of "an empty list means
 * everything", because an empty organizer list and an empty course list mean
 * different things when the corresponding kind is included. Use the named
 * constructors to express the common cases.
 */
final readonly class EventFilter {

  /**
   * The legacy event type keys, in display order.
   *
   * These keys are still accepted by the iCal feed and by EventFilterFactory,
   * so existing subscription URLs keep working. They are not used by the
   * blocks or by the occurrence repository anymore: the "party" and
   * "external_party" keys map to the organizer terms, "course" selects the
   * course bundle.
   */
  public const array TYPES = ['course', 'party', 'external_party'];

  /**
   * Constructs an EventFilter.
   *
   * @param bool $includeEvents
   *   Whether events are included. Together with an empty $organizerIds this
   *   means every event.
   * @param int[] $organizerIds
   *   The organizer term IDs to include. An empty list means every organizer.
   * @param bool $includeCourses
   *   Whether courses are included. Together with an empty $courseIds this
   *   means every course.
   * @param int[] $courseIds
   *   The course node IDs to include. An empty list means every course.
   * @param int[] $locationIds
   *   The location term IDs to include. An empty list means every location.
   */
  public function __construct(
    public bool $includeEvents = TRUE,
    public array $organizerIds = [],
    public bool $includeCourses = TRUE,
    public array $courseIds = [],
    public array $locationIds = [],
  ) {}

  /**
   * Builds a filter for events only.
   *
   * @param int[] $organizerIds
   *   The organizer term IDs, empty for every organizer.
   * @param int[] $locationIds
   *   The location term IDs, empty for every location.
   *
   * @return self
   *   The filter.
   */
  public static function eventsOnly(array $organizerIds = [], array $locationIds = []): self {
    return new self(
      includeEvents: TRUE,
      organizerIds: $organizerIds,
      includeCourses: FALSE,
      locationIds: $locationIds,
    );
  }

  /**
   * Builds a filter for courses only.
   *
   * @param int[] $courseIds
   *   The course node IDs, empty for every course.
   * @param int[] $locationIds
   *   The location term IDs, empty for every location.
   *
   * @return self
   *   The filter.
   */
  public static function coursesOnly(array $courseIds = [], array $locationIds = []): self {
    return new self(
      includeEvents: FALSE,
      includeCourses: TRUE,
      courseIds: $courseIds,
      locationIds: $locationIds,
    );
  }

  /**
   * Returns whether the filter restricts the result.
   */
  public function isEmpty(): bool {
    return $this->includeEvents
      && $this->includeCourses
      && $this->organizerIds === []
      && $this->courseIds === []
      && $this->locationIds === [];
  }

  /**
   * Returns the filter as query parameters.
   *
   * The organizer, course and location selections use the comma separated
   * notation of the iCal feed. A course-only filter additionally emits the
   * legacy `type=course` parameter, so the result can be parsed back.
   *
   * @return array<string, string>
   *   The query parameters.
   */
  public function toQuery(): array {
    $query = [];
    if ($this->organizerIds !== []) {
      $query['organizer'] = implode(',', $this->organizerIds);
    }
    if ($this->courseIds !== []) {
      $query['course'] = implode(',', $this->courseIds);
    }
    if ($this->locationIds !== []) {
      $query['location'] = implode(',', $this->locationIds);
    }
    if ($this->includeCourses && !$this->includeEvents) {
      $query['type'] = 'course';
    }
    return $query;
  }

}
