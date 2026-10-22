<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\workspace_events\EventFilter;
use Drupal\workspace_events\EventOccurrence;
use Drupal\workspace_events\EventOccurrenceRepository;

/**
 * Prepares the data of the course detail page.
 */
final class CoursePreprocessHooks {

  use StringTranslationTrait;

  /**
   * The number of upcoming dates shown on the detail page.
   */
  private const int NEXT_DATES = 6;

  /**
   * The number of days to look ahead for upcoming dates.
   */
  private const int LOOKAHEAD_DAYS = 365;

  /**
   * Constructs the course preprocess hooks.
   *
   * @param \Drupal\workspace_events\EventOccurrenceRepository $repository
   *   The occurrence repository.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   */
  public function __construct(
    private readonly EventOccurrenceRepository $repository,
    private readonly TimeInterface $time,
    private readonly RouteMatchInterface $routeMatch,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Implements hook_preprocess_node().
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   *
   * @param-out array<mixed> $variables
   */
  #[Hook('preprocess_node')]
  public function preprocessNode(array &$variables): void {
    $node = $variables['node'] ?? NULL;
    if (!$node instanceof NodeInterface || $node->bundle() !== 'course') {
      return;
    }
    if (($variables['view_mode'] ?? NULL) !== 'full') {
      return;
    }

    $occurrences = $this->findUpcomingOccurrences($node);

    $variables['course_level'] = $node->hasField('field_level')
      ? $node->get('field_level')->getString()
      : '';
    $variables['course_schedule'] = isset($occurrences[0])
      ? $this->formatSchedule($occurrences[0])
      : '';
    $variables['course_next_dates'] = $this->buildNextDates($occurrences);
    $variables['course_location'] = $this->buildLocation($node);
    $variables['course_link'] = $this->buildLink($node);
    $variables['course_ical_url'] = Url::fromRoute('workspace_events.ical', [], [
      'query' => ['course' => (int) $node->id()],
    ])->toString();
    $variables['course_overview_url'] = Url::fromUserInput('/salsa-kurse')->toString();

    // The list shifts as soon as the next date has ended, so it may not be
    // cached beyond the end of the next occurrence.
    $max_age = 3600;
    if ($occurrences !== []) {
      $max_age = max(0, $occurrences[0]->endTimestamp() - $this->time->getRequestTime());
    }

    $cacheability = CacheableMetadata::createFromRenderArray($variables);
    $cacheability->addCacheableDependency($node);
    $location = $node->hasField('field_location') ? $node->get('field_location')->entity : NULL;
    if ($location instanceof TermInterface) {
      $cacheability->addCacheableDependency($location);
    }
    // The body is rendered through its text format.
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $format = $node->get('body')->first()?->get('format')->getString() ?? '';
      if ($format !== '') {
        $cacheability->addCacheTags(['config:filter.format.' . $format]);
      }
    }
    $cacheability->setCacheMaxAge($max_age);
    $cacheability->applyTo($variables);
  }

  /**
   * Implements hook_preprocess_page_title().
   *
   * Course pages render their own <h1> in the header of the node template, so
   * the route's page title block stays empty.
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   *
   * @param-out array<mixed> $variables
   */
  #[Hook('preprocess_page_title')]
  public function preprocessPageTitle(array &$variables): void {
    if ($this->routeMatch->getRouteName() !== 'entity.node.canonical') {
      return;
    }
    $node = $this->routeMatch->getParameter('node');
    if (!$node instanceof NodeInterface || $node->bundle() !== 'course') {
      return;
    }

    $variables['title'] = '';

    // The title depends on the route, just like the route's main content.
    $cache = $variables['#cache'] ?? [];
    $cache = is_array($cache) ? $cache : [];
    $contexts = $cache['contexts'] ?? [];
    $contexts = is_array($contexts) ? $contexts : [];
    $contexts[] = 'route';
    $cache['contexts'] = $contexts;
    $variables['#cache'] = $cache;
  }

  /**
   * Returns the next occurrences of a course.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The course node.
   *
   * @return \Drupal\workspace_events\EventOccurrence[]
   *   The upcoming occurrences, sorted by start date and time.
   */
  private function findUpcomingOccurrences(NodeInterface $node): array {
    $timezone = new \DateTimeZone(date_default_timezone_get());
    $from = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))->setTimezone($timezone);
    return $this->repository->findOccurrences(
      EventFilter::coursesOnly([(int) $node->id()]),
      $from,
      $from->modify('+' . self::LOOKAHEAD_DAYS . ' days'),
      self::NEXT_DATES,
    );
  }

  /**
   * Builds the rows of the upcoming dates list.
   *
   * @param \Drupal\workspace_events\EventOccurrence[] $occurrences
   *   The upcoming occurrences.
   *
   * @return array<int, array{date: string, date_iso: string, weekday: string, time: string}>
   *   The date rows.
   */
  private function buildNextDates(array $occurrences): array {
    $dates = [];
    foreach ($occurrences as $occurrence) {
      $dates[] = [
        'date' => $occurrence->start->format('d.m.Y'),
        'date_iso' => $occurrence->start->format('Y-m-d'),
        'weekday' => $this->formatWeekday($occurrence),
        'time' => $this->formatTime($occurrence) ?? '',
      ];
    }
    return $dates;
  }

  /**
   * Formats the weekday and time of the series.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The next occurrence of the course.
   *
   * @return string
   *   The weekday and time, joined for display.
   */
  private function formatSchedule(EventOccurrence $occurrence): string {
    $parts = array_values(array_filter([
      $this->formatWeekday($occurrence),
      $this->formatTime($occurrence) ?? '',
    ]));
    return implode(' · ', $parts);
  }

  /**
   * Returns the translated weekday of an occurrence.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   *
   * @return string
   *   The weekday in the current interface language.
   */
  private function formatWeekday(EventOccurrence $occurrence): string {
    return $this->dateFormatter->format(
      $occurrence->start->getTimestamp(),
      'custom',
      'l',
      $occurrence->start->getTimezone()->getName(),
    );
  }

  /**
   * Formats the time of an occurrence for the detail page.
   *
   * Unlike the event cards and blocks, the detail page omits the "Uhr"
   * suffix, so the weekday line stays compact.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   *
   * @return string|null
   *   The formatted time, or NULL for all-day events.
   */
  private function formatTime(EventOccurrence $occurrence): ?string {
    if ($occurrence->allDay) {
      return NULL;
    }
    $start = $occurrence->start->format('H:i');
    if ($occurrence->end === NULL) {
      return (string) $this->t('ab @start', ['@start' => $start]);
    }
    return (string) $this->t('@start – @end', [
      '@start' => $start,
      '@end' => $occurrence->end->format('H:i'),
    ]);
  }

  /**
   * Builds the location card data of a course.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The course node.
   *
   * @return array{name: string, address: string, map_url: string}|null
   *   The location, or NULL when the course has no location.
   */
  private function buildLocation(NodeInterface $node): ?array {
    if (!$node->hasField('field_location')) {
      return NULL;
    }
    $term = $node->get('field_location')->entity;
    if (!$term instanceof TermInterface) {
      return NULL;
    }

    $address = '';
    if ($term->hasField('field_address') && !$term->get('field_address')->isEmpty()) {
      $address = trim($term->get('field_address')->getString());
    }

    $map_url = '';
    if ($term->hasField('field_map_link') && !$term->get('field_map_link')->isEmpty()) {
      $uri = $term->get('field_map_link')->first()?->get('uri')->getString() ?? '';
      if ($uri !== '') {
        try {
          $map_url = Url::fromUri($uri)->toString();
        }
        catch (\InvalidArgumentException) {
          // Fall back to the address search below.
        }
      }
    }
    if ($map_url === '' && $address !== '') {
      $query = preg_replace('/\s*\R\s*/u', ', ', $address) ?? $address;
      $map_url = 'https://www.openstreetmap.org/search?query=' . rawurlencode($query);
    }

    return [
      'name' => (string) $term->label(),
      'address' => $address,
      'map_url' => $map_url,
    ];
  }

  /**
   * Builds the optional external link of a course.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The course node.
   *
   * @return array{label: string, href: string}|null
   *   The link, or NULL when the course has no link.
   */
  private function buildLink(NodeInterface $node): ?array {
    if (!$node->hasField('field_link') || $node->get('field_link')->isEmpty()) {
      return NULL;
    }
    $item = $node->get('field_link')->first();
    if ($item === NULL) {
      return NULL;
    }

    $uri = $item->get('uri')->getString();
    if ($uri === '') {
      return NULL;
    }
    try {
      $href = Url::fromUri($uri)->toString();
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }

    $title = trim($item->get('title')->getString());
    return [
      'label' => $title === '' ? (string) $this->t('Weitere Informationen') : $title,
      'href' => $href,
    ];
  }

}
