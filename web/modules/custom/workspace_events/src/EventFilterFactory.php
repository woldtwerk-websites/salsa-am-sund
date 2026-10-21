<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Symfony\Component\HttpFoundation\InputBag;

/**
 * Builds an EventFilter from request query parameters.
 *
 * Supported parameters:
 * - `organizer` (term IDs, comma separated or repeated): only the events of
 *   these organizers.
 * - `course` (node IDs, comma separated or repeated): only these courses.
 * - `location` (term IDs, comma separated or repeated).
 * - `type` (legacy, comma separated or repeated): `course` selects the course
 *   bundle, `party` and `external_party` map to the fixed organizer terms.
 *
 * Selections are combined with OR, so `?organizer=1&course=2` returns the
 * events of organizer 1 and course 2. Without any type selection the filter
 * contains every event and every course.
 */
final class EventFilterFactory {

  /**
   * Constructs an EventFilterFactory.
   *
   * @param \Drupal\workspace_events\OrganizerLookupInterface $organizerLookup
   *   The lookup for the fixed organizer terms.
   */
  public function __construct(
    private readonly OrganizerLookupInterface $organizerLookup,
  ) {}

  /**
   * Builds a filter from request query parameters.
   *
   * @param \Symfony\Component\HttpFoundation\InputBag<string>|array<string, mixed> $query
   *   The query parameters.
   *
   * @return \Drupal\workspace_events\EventFilter
   *   The filter.
   */
  public function fromQuery(InputBag|array $query): EventFilter {
    $legacy_types = [];
    foreach (self::queryValues($query, 'type') as $value) {
      foreach (self::split($value) as $candidate) {
        if (in_array($candidate, EventFilter::TYPES, TRUE) && !in_array($candidate, $legacy_types, TRUE)) {
          $legacy_types[] = $candidate;
        }
      }
    }

    $organizer_ids = self::parseIds($query, 'organizer');
    foreach ($legacy_types as $legacy_type) {
      $uuid = OrganizerResolver::LEGACY_TYPE_UUIDS[$legacy_type] ?? NULL;
      if ($uuid === NULL) {
        continue;
      }
      $id = $this->organizerLookup->id($uuid);
      if ($id !== NULL && !in_array($id, $organizer_ids, TRUE)) {
        $organizer_ids[] = $id;
      }
    }

    $course_ids = self::parseIds($query, 'course');
    $location_ids = self::parseIds($query, 'location');

    $restricted = $legacy_types !== [] || $organizer_ids !== [] || $course_ids !== [];
    if (!$restricted) {
      // Only locations were selected: that restricts both kinds, like before.
      return new EventFilter(locationIds: $location_ids);
    }

    $include_courses = in_array('course', $legacy_types, TRUE) || $course_ids !== [];
    // A course selection alone means "only these courses"; an organizer
    // selection always includes events.
    $include_events = $organizer_ids !== [] || !$include_courses;

    return new EventFilter(
      includeEvents: $include_events,
      organizerIds: $organizer_ids,
      includeCourses: $include_courses,
      courseIds: $course_ids,
      locationIds: $location_ids,
    );
  }

  /**
   * Parses a list of positive IDs from a query parameter.
   *
   * @param \Symfony\Component\HttpFoundation\InputBag<string>|array<string, mixed> $query
   *   The query parameters.
   * @param string $key
   *   The parameter name.
   *
   * @return int[]
   *   The unique, positive IDs in the order of appearance.
   */
  private static function parseIds(InputBag|array $query, string $key): array {
    $ids = [];
    foreach (self::queryValues($query, $key) as $value) {
      foreach (self::split($value) as $candidate) {
        $id = filter_var($candidate, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id !== FALSE && !in_array($id, $ids, TRUE)) {
          $ids[] = $id;
        }
      }
    }
    return $ids;
  }

  /**
   * Extracts the raw values for a query parameter.
   *
   * @param \Symfony\Component\HttpFoundation\InputBag<string>|array<string, mixed> $query
   *   The query parameters.
   * @param string $key
   *   The parameter name.
   *
   * @return array<int, mixed>
   *   The raw values.
   */
  private static function queryValues(InputBag|array $query, string $key): array {
    $parameters = $query instanceof InputBag ? $query->all() : $query;
    if (!array_key_exists($key, $parameters)) {
      return [];
    }
    return is_array($parameters[$key]) ? array_values($parameters[$key]) : [$parameters[$key]];
  }

  /**
   * Flattens a value into trimmed strings.
   *
   * @param mixed $value
   *   A scalar or an array of scalars.
   *
   * @return string[]
   *   The trimmed, non-empty values.
   */
  private static function split(mixed $value): array {
    if (is_array($value)) {
      $parts = [];
      foreach ($value as $part) {
        $parts = array_merge($parts, self::split($part));
      }
      return $parts;
    }
    if (!is_scalar($value)) {
      return [];
    }
    $parts = [];
    foreach (explode(',', (string) $value) as $part) {
      $part = trim($part);
      if ($part !== '') {
        $parts[] = $part;
      }
    }
    return $parts;
  }

}
