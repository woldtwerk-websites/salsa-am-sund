<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Builds schema.org JSON-LD for course and event detail pages.
 */
final class StructuredDataBuilder {

  /**
   * The schema.org context URL.
   */
  private const string CONTEXT = 'https://schema.org';

  /**
   * Constructs a StructuredDataBuilder.
   *
   * @param \Drupal\workspace_events\EventDescriptionFormatter $descriptionFormatter
   *   The event description formatter.
   */
  public function __construct(
    private readonly EventDescriptionFormatter $descriptionFormatter,
  ) {}

  /**
   * Builds the structured data of a course or event detail page.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The course or event node.
   * @param \Drupal\workspace_events\EventOccurrence[] $occurrences
   *   The upcoming occurrences shown on the detail page.
   *
   * @return array<string, mixed>
   *   The JSON-LD data, or an empty array when the page has no structured
   *   data.
   */
  public function build(NodeInterface $node, array $occurrences): array {
    if ($node->bundle() === 'event') {
      return $this->buildEvent($node, $occurrences);
    }
    if ($node->bundle() === 'course') {
      return $this->buildCourse($node, $occurrences);
    }
    return [];
  }

  /**
   * Builds the structured data of an event page.
   *
   * Every upcoming occurrence is described as its own Event, so search engines
   * always get a concrete start and end date instead of a recurrence rule.
   * Past events have no structured data; the page is no longer about an
   * upcoming event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   * @param \Drupal\workspace_events\EventOccurrence[] $occurrences
   *   The upcoming occurrences.
   *
   * @return array<string, mixed>
   *   The JSON-LD data, or an empty array when there are no occurrences.
   */
  private function buildEvent(NodeInterface $node, array $occurrences): array {
    if ($occurrences === []) {
      return [];
    }

    $url = $this->getCanonicalUrl($node);
    $description = $this->descriptionFormatter->toPlainText($node);
    $same_as = $this->getExternalLink($node);
    $place = $this->buildPlace($node);

    $events = [];
    foreach ($occurrences as $occurrence) {
      $event = [
        '@type' => 'Event',
        '@id' => $url . '#event-' . $occurrence->delta,
        'name' => (string) $node->label(),
        'url' => $url,
        'startDate' => $this->formatStart($occurrence),
        'eventStatus' => self::CONTEXT . '/EventScheduled',
        'eventAttendanceMode' => self::CONTEXT . '/OfflineEventAttendanceMode',
      ];
      $end = $this->formatEnd($occurrence);
      if ($end !== NULL) {
        $event['endDate'] = $end;
      }
      if ($description !== '') {
        $event['description'] = $description;
      }
      if ($same_as !== NULL) {
        $event['sameAs'] = $same_as;
      }
      if ($place !== NULL) {
        $event['location'] = $place;
      }
      $organizer = $this->buildOrganizer($occurrence->organizer);
      if ($organizer !== NULL) {
        $event['organizer'] = $organizer;
      }
      $events[] = $event;
    }

    return [
      '@context' => self::CONTEXT,
      '@graph' => $events,
    ];
  }

  /**
   * Builds the structured data of a course page.
   *
   * A course is a series, so the page describes the course itself and one
   * CourseInstance per upcoming date.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The course node.
   * @param \Drupal\workspace_events\EventOccurrence[] $occurrences
   *   The upcoming occurrences.
   *
   * @return array<string, mixed>
   *   The JSON-LD data.
   */
  private function buildCourse(NodeInterface $node, array $occurrences): array {
    $url = $this->getCanonicalUrl($node);
    $description = $this->descriptionFormatter->toPlainText($node);
    $place = $this->buildPlace($node);

    $course = [
      '@type' => 'Course',
      '@id' => $url . '#course',
      'name' => (string) $node->label(),
      'url' => $url,
      'provider' => $this->buildCourseProvider(),
    ];
    if ($description !== '') {
      $course['description'] = $description;
    }

    $instances = [];
    foreach ($occurrences as $occurrence) {
      $instance = [
        '@type' => 'CourseInstance',
        'courseMode' => 'onsite',
        'startDate' => $this->formatStart($occurrence),
      ];
      $end = $this->formatEnd($occurrence);
      if ($end !== NULL) {
        $instance['endDate'] = $end;
      }
      if ($place !== NULL) {
        $instance['location'] = $place;
      }
      $instances[] = $instance;
    }
    if ($instances !== []) {
      $course['hasCourseInstance'] = $instances;
    }

    return [
      '@context' => self::CONTEXT,
      '@graph' => [$course],
    ];
  }

  /**
   * Returns the canonical absolute URL of a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return string
   *   The absolute canonical URL.
   */
  private function getCanonicalUrl(NodeInterface $node): string {
    return $node->toUrl('canonical', ['absolute' => TRUE])->toString();
  }

  /**
   * Returns the external link of a course or event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The course or event node.
   *
   * @return string|null
   *   The absolute external URL, or NULL when the node has no link.
   */
  private function getExternalLink(NodeInterface $node): ?string {
    if (!$node->hasField('field_link') || $node->get('field_link')->isEmpty()) {
      return NULL;
    }
    $uri = $node->get('field_link')->first()?->get('uri')->getString() ?? '';
    if ($uri === '') {
      return NULL;
    }
    try {
      return Url::fromUri($uri, ['absolute' => TRUE])->toString();
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

  /**
   * Formats the start of an occurrence as an ISO 8601 value.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   *
   * @return string
   *   The date, or just the day for all-day events.
   */
  private function formatStart(EventOccurrence $occurrence): string {
    return $occurrence->allDay
      ? $occurrence->start->format('Y-m-d')
      : $occurrence->start->format(\DateTimeInterface::ATOM);
  }

  /**
   * Formats the end of an occurrence as an ISO 8601 value.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   *
   * @return string|null
   *   The end date, or NULL when the occurrence has no known end.
   */
  private function formatEnd(EventOccurrence $occurrence): ?string {
    if ($occurrence->end === NULL) {
      return NULL;
    }
    return $occurrence->allDay
      ? $occurrence->end->format('Y-m-d')
      : $occurrence->end->format(\DateTimeInterface::ATOM);
  }

  /**
   * Builds the location of a course or event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The course or event node.
   *
   * @return array<string, mixed>|null
   *   The schema.org Place, or NULL when the node has no location.
   */
  private function buildPlace(NodeInterface $node): ?array {
    if (!$node->hasField('field_location')) {
      return NULL;
    }
    $term = $node->get('field_location')->entity;
    if (!$term instanceof TermInterface) {
      return NULL;
    }

    $place = [
      '@type' => 'Place',
      'name' => (string) $term->label(),
    ];

    $address = $this->getLocationAddress($term);
    if ($address !== '') {
      $place['address'] = $this->buildPostalAddress($address);
    }

    $map_url = $this->getLocationMapUrl($term);
    if ($map_url !== NULL) {
      $place['hasMap'] = $map_url;
    }

    return $place;
  }

  /**
   * Returns the address of a location term.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The location term.
   *
   * @return string
   *   The address, or an empty string when the term has none.
   */
  private function getLocationAddress(TermInterface $term): string {
    if (!$term->hasField('field_address') || $term->get('field_address')->isEmpty()) {
      return '';
    }
    return trim($term->get('field_address')->getString());
  }

  /**
   * Returns the map URL of a location term.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The location term.
   *
   * @return string|null
   *   The absolute map URL, or NULL when the term has none.
   */
  private function getLocationMapUrl(TermInterface $term): ?string {
    if (!$term->hasField('field_map_link') || $term->get('field_map_link')->isEmpty()) {
      return NULL;
    }
    $uri = $term->get('field_map_link')->first()?->get('uri')->getString() ?? '';
    if ($uri === '') {
      return NULL;
    }
    try {
      return Url::fromUri($uri, ['absolute' => TRUE])->toString();
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

  /**
   * Splits a free-form address into a schema.org PostalAddress.
   *
   * The addresses are entered as lines: one or more street lines followed by
   * the postal code and city. The last line is only treated as postal code and
   * city when it starts with digits, so a plain street address still works.
   *
   * @param string $address
   *   The address as entered on the location term.
   *
   * @return array<string, string>
   *   The schema.org PostalAddress.
   */
  private function buildPostalAddress(string $address): array {
    $lines = preg_split('/\R/u', $address) ?: [];
    $lines = array_values(array_filter(
      array_map(static fn (string $line): string => trim($line), $lines),
      static fn (string $line): bool => $line !== '',
    ));

    $postal_code = '';
    $locality = '';
    $last = $lines === [] ? '' : $lines[array_key_last($lines)];
    if (preg_match('/^([0-9]{4,10})\s+(.+)$/u', $last, $matches) === 1) {
      $postal_code = $matches[1];
      $locality = $matches[2];
      array_pop($lines);
    }

    $postal_address = ['@type' => 'PostalAddress'];
    $street = implode(', ', $lines);
    if ($street !== '') {
      $postal_address['streetAddress'] = $street;
    }
    if ($postal_code !== '') {
      $postal_address['postalCode'] = $postal_code;
    }
    if ($locality !== '') {
      $postal_address['addressLocality'] = $locality;
    }
    $postal_address['addressCountry'] = 'DE';

    return $postal_address;
  }

  /**
   * Builds the organizer of an event.
   *
   * @param \Drupal\taxonomy\TermInterface|null $organizer
   *   The organizer term, if any.
   *
   * @return array<string, string>|null
   *   The schema.org Organization, or NULL when the event has no organizer.
   */
  private function buildOrganizer(?TermInterface $organizer): ?array {
    if ($organizer === NULL) {
      return NULL;
    }

    $data = [
      '@type' => 'Organization',
      'name' => (string) $organizer->label(),
    ];
    if ((string) $organizer->uuid() === OrganizerResolver::SALSA_AM_SUND_UUID) {
      $data['url'] = $this->getFrontUrl();
    }
    return $data;
  }

  /**
   * Builds the provider of a course.
   *
   * @return array<string, string>
   *   The schema.org Organization.
   */
  private function buildCourseProvider(): array {
    return [
      '@type' => 'Organization',
      'name' => 'Salsa am Sund',
      'url' => $this->getFrontUrl(),
    ];
  }

  /**
   * Returns the absolute URL of the front page.
   */
  private function getFrontUrl(): string {
    return Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();
  }

}
