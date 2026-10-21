<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Symfony\Component\HttpFoundation\InputBag;

/**
 * Immutable filter for event occurrences.
 */
final readonly class EventFilter {

  /**
   * The supported event type keys, in display order.
   */
  public const array TYPES = ['course', 'party', 'external_party'];

  /**
   * Constructs an EventFilter.
   *
   * @param string[] $types
   *   The event type keys to include. An empty list means all types.
   * @param int[] $locationIds
   *   The taxonomy term IDs to include. An empty list means all locations.
   */
  public function __construct(
    public array $types = [],
    public array $locationIds = [],
  ) {}

  /**
   * Builds a filter from request query parameters.
   *
   * Supports both comma separated values (`type=course,party`) and repeated
   * parameters (`type[]=course&type[]=party`). Invalid values are ignored.
   *
   * @param \Symfony\Component\HttpFoundation\InputBag<string>|array<string, mixed> $query
   *   The query parameters.
   *
   * @return self
   *   The filter.
   */
  public static function fromQuery(InputBag|array $query): self {
    $types = [];
    foreach (self::queryValues($query, 'type') as $value) {
      foreach (self::split($value) as $candidate) {
        if (in_array($candidate, self::TYPES, TRUE) && !in_array($candidate, $types, TRUE)) {
          $types[] = $candidate;
        }
      }
    }

    $location_ids = [];
    foreach (self::queryValues($query, 'location') as $value) {
      foreach (self::split($value) as $candidate) {
        $id = filter_var($candidate, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id !== FALSE && !in_array($id, $location_ids, TRUE)) {
          $location_ids[] = $id;
        }
      }
    }

    return new self($types, $location_ids);
  }

  /**
   * Returns whether the filter restricts the result.
   */
  public function isEmpty(): bool {
    return $this->types === [] && $this->locationIds === [];
  }

  /**
   * Returns the filter as query parameters.
   *
   * @return array<string, string>
   *   The query parameters, using the comma separated notation.
   */
  public function toQuery(): array {
    $query = [];
    if ($this->types !== []) {
      $query['type'] = implode(',', $this->types);
    }
    if ($this->locationIds !== []) {
      $query['location'] = implode(',', $this->locationIds);
    }
    return $query;
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
