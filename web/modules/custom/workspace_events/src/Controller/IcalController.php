<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Controller;

use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\workspace_events\EventDescriptionFormatter;
use Drupal\workspace_events\EventFilter;
use Drupal\workspace_events\EventOccurrence;
use Drupal\workspace_events\EventOccurrenceRepository;
use Drupal\taxonomy\TermInterface;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides a filterable iCal feed of event occurrences.
 */
final class IcalController implements ContainerInjectionInterface {

  /**
   * Cached event type labels, keyed by type.
   *
   * @var array<string, string>|null
   */
  private ?array $typeLabels = NULL;

  /**
   * The timezone used for date output.
   */
  private const string TIMEZONE = 'Europe/Berlin';

  /**
   * The number of days shown after today.
   */
  private const int FUTURE_DAYS = 365;

  /**
   * The default number of days shown in the past.
   */
  private const int DEFAULT_PAST_DAYS = 30;

  /**
   * The maximum number of days that may be requested in the past.
   */
  private const int MAX_PAST_DAYS = 90;

  /**
   * Constructs an IcalController.
   *
   * @param \Drupal\workspace_events\EventOccurrenceRepository $repository
   *   The occurrence repository.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\workspace_events\EventDescriptionFormatter $descriptionFormatter
   *   The event description formatter.
   */
  public function __construct(
    private readonly EventOccurrenceRepository $repository,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventDescriptionFormatter $descriptionFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $repository = $container->get(EventOccurrenceRepository::class);
    \assert($repository instanceof EventOccurrenceRepository);
    $entity_type_manager = $container->get(EntityTypeManagerInterface::class);
    \assert($entity_type_manager instanceof EntityTypeManagerInterface);
    $description_formatter = $container->get(EventDescriptionFormatter::class);
    \assert($description_formatter instanceof EventDescriptionFormatter);

    return new static($repository, $entity_type_manager, $description_formatter);
  }

  /**
   * Builds the calendar response.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Drupal\Core\Cache\CacheableResponse
   *   The iCal response.
   */
  public function __invoke(Request $request): CacheableResponse {
    $timezone = new \DateTimeZone(self::TIMEZONE);
    $now = new \DateTimeImmutable('now', $timezone);
    $past_days = $this->getPastDays($request);
    $from = $now->modify('-' . $past_days . ' days');
    $to = $now->modify('+' . self::FUTURE_DAYS . ' days');

    $filter = EventFilter::fromQuery($request->query);
    $occurrences = $this->repository->findOccurrences($filter, $from, $to);

    $calendar = $this->buildCalendar($request, $filter, $occurrences);
    $response = new CacheableResponse($calendar->serialize(), Response::HTTP_OK, [
      'Content-Type' => 'text/calendar; charset=utf-8',
      'Content-Disposition' => 'inline; filename="salsa-am-sund.ics"',
    ]);

    $cacheability = $response->getCacheableMetadata();
    $cacheability->addCacheContexts([
      'url.query_args:type',
      'url.query_args:location',
      'url.query_args:past',
    ]);
    $cacheability->addCacheTags([
      'node_list:event',
      'taxonomy_term_list:location',
    ]);
    foreach ($occurrences as $occurrence) {
      $cacheability->addCacheableDependency($occurrence->node);
      if ($occurrence->location !== NULL) {
        $cacheability->addCacheableDependency($occurrence->location);
      }
    }
    // The window is relative to the current time, so the response is only
    // valid for a limited period.
    $cacheability->setCacheMaxAge(3600);

    return $response;
  }

  /**
   * Builds the iCal calendar object.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param \Drupal\workspace_events\EventFilter $filter
   *   The applied filter.
   * @param \Drupal\workspace_events\EventOccurrence[] $occurrences
   *   The occurrences to add.
   *
   * @return \Sabre\VObject\Component\VCalendar
   *   The calendar.
   */
  // @phpstan-ignore-next-line
  private function buildCalendar(Request $request, EventFilter $filter, array $occurrences): VCalendar {
    $calendar = new VCalendar();
    $calendar->remove('PRODID');
    $calendar->add('PRODID', '-//Salsa am Sund//Termine//DE');
    $calendar->remove('VERSION');
    $calendar->add('VERSION', '2.0');
    $calendar->remove('CALSCALE');
    $calendar->add('CALSCALE', 'GREGORIAN');
    $calendar->add('METHOD', 'PUBLISH');
    $calendar->add('X-WR-CALNAME', $this->getCalendarName($filter));
    $calendar->add('X-WR-TIMEZONE', self::TIMEZONE);
    $calendar->add('REFRESH-INTERVAL', 'PT6H', ['VALUE' => 'DURATION']);
    $calendar->add('X-PUBLISHED-TTL', 'PT6H');
    $this->addTimezone($calendar);

    $host = $request->getHost();
    $scheme_and_host = $request->getSchemeAndHttpHost();
    foreach ($occurrences as $occurrence) {
      $this->addEvent($calendar, $occurrence, $host, $scheme_and_host);
    }

    return $calendar;
  }

  /**
   * Adds the Europe/Berlin timezone definition.
   *
   * @param \Sabre\VObject\Component\VCalendar $calendar
   *   The calendar.
   */
  // @phpstan-ignore-next-line
  private function addTimezone(VCalendar $calendar): void {
    $timezone = $calendar->add('VTIMEZONE');
    \assert($timezone instanceof Component);
    $timezone->add('TZID', self::TIMEZONE);

    $daylight = $calendar->createComponent('DAYLIGHT', [], FALSE);
    $timezone->add($daylight);
    $daylight->add('TZOFFSETFROM', '+0100');
    $daylight->add('TZOFFSETTO', '+0200');
    $daylight->add('TZNAME', 'CEST');
    $daylight->add('DTSTART', '19700329T020000');
    $daylight->add('RRULE', 'FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU');

    $standard = $calendar->createComponent('STANDARD', [], FALSE);
    $timezone->add($standard);
    $standard->add('TZOFFSETFROM', '+0200');
    $standard->add('TZOFFSETTO', '+0100');
    $standard->add('TZNAME', 'CET');
    $standard->add('DTSTART', '19701025T030000');
    $standard->add('RRULE', 'FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU');
  }

  /**
   * Adds a single event to the calendar.
   *
   * @param \Sabre\VObject\Component\VCalendar $calendar
   *   The calendar.
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   * @param string $host
   *   The request host, used for the UID.
   * @param string $schemeAndHost
   *   The request scheme and host, used for absolute URLs.
   */
  // @phpstan-ignore-next-line
  private function addEvent(VCalendar $calendar, EventOccurrence $occurrence, string $host, string $schemeAndHost): void {
    $node = $occurrence->node;
    $event = $calendar->add('VEVENT');
    \assert($event instanceof VEvent);
    $event->remove('UID');
    $event->add('UID', 'event-' . $node->id() . '-' . $occurrence->delta . '@' . $host);

    $changed = (new \DateTimeImmutable('@' . $node->getChangedTime()))
      ->setTimezone(new \DateTimeZone('UTC'));
    $event->remove('DTSTAMP');
    $event->add('DTSTAMP', $changed);
    $event->add('LAST-MODIFIED', $changed);
    $event->add('STATUS', 'CONFIRMED');

    $event->add('SUMMARY', $this->getSummary($occurrence));
    $event->add('DESCRIPTION', $this->getDescription($occurrence, $schemeAndHost));
    $event->add('LOCATION', $this->getLocation($occurrence));
    $event->add('URL', $this->getUrl($occurrence, $schemeAndHost));
    $event->add('CATEGORIES', $this->getTypeLabel($occurrence->type));

    if ($occurrence->allDay) {
      $event->add('DTSTART', $occurrence->start->format('Ymd'), ['VALUE' => 'DATE']);
      $event->add('DTEND', $occurrence->start->modify('+1 day')->format('Ymd'), ['VALUE' => 'DATE']);
      return;
    }

    $event->add('DTSTART', $occurrence->start->format('Ymd\THis'), ['TZID' => self::TIMEZONE]);
    if ($occurrence->end !== NULL) {
      $event->add('DTEND', $occurrence->end->format('Ymd\THis'), ['TZID' => self::TIMEZONE]);
    }
  }

  /**
   * Builds the calendar name including a filter summary.
   *
   * @param \Drupal\workspace_events\EventFilter $filter
   *   The applied filter.
   *
   * @return string
   *   The calendar name.
   */
  private function getCalendarName(EventFilter $filter): string {
    $name = 'Salsa am Sund – Termine';
    if ($filter->isEmpty()) {
      return $name;
    }

    $parts = [];
    foreach ($filter->types as $type) {
      $parts[] = $this->getTypeLabel($type);
    }
    if ($filter->locationIds !== []) {
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')
        ->loadMultiple($filter->locationIds);
      $locations = [];
      foreach ($terms as $term) {
        \assert($term instanceof TermInterface);
        $locations[] = (string) $term->label();
      }
      if ($locations !== []) {
        $parts[] = implode(', ', $locations);
      }
    }
    if ($parts === []) {
      return $name;
    }
    return $name . ' – ' . implode(', ', $parts);
  }

  /**
   * Returns the event summary.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   *
   * @return string
   *   The summary.
   */
  private function getSummary(EventOccurrence $occurrence): string {
    $title = (string) $occurrence->node->label();
    $level = $occurrence->node->get('field_level')->getString();
    if ($occurrence->type === 'course' && $level !== '') {
      return $title . ' (' . $level . ')';
    }
    return $title;
  }

  /**
   * Returns the plain text description including the node URL.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   * @param string $schemeAndHost
   *   The request scheme and host.
   *
   * @return string
   *   The description.
   */
  private function getDescription(EventOccurrence $occurrence, string $schemeAndHost): string {
    $node = $occurrence->node;
    $description = $this->descriptionFormatter->toPlainText($node);
    $url = $schemeAndHost . $node->toUrl()->toString();
    return trim($description . "\n\n" . $url);
  }

  /**
   * Returns the location string.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   *
   * @return string
   *   The location, or an empty string.
   */
  private function getLocation(EventOccurrence $occurrence): string {
    $term = $occurrence->location;
    if ($term === NULL) {
      return '';
    }
    $parts = [(string) $term->label()];
    if ($term->hasField('field_address') && !$term->get('field_address')->isEmpty()) {
      $address = preg_replace('/\s*\R\s*/u', ', ', trim($term->get('field_address')->getString()));
      if ($address !== NULL && $address !== '') {
        $parts[] = $address;
      }
    }
    return implode(', ', $parts);
  }

  /**
   * Returns the event URL.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   * @param string $schemeAndHost
   *   The request scheme and host.
   *
   * @return string
   *   The URL.
   */
  private function getUrl(EventOccurrence $occurrence, string $schemeAndHost): string {
    $node = $occurrence->node;
    if ($node->hasField('field_link') && !$node->get('field_link')->isEmpty()) {
      $uri = $node->get('field_link')->first()?->get('uri')->getString() ?? '';
      if ($uri !== '') {
        return $uri;
      }
    }
    return $schemeAndHost . $node->toUrl()->toString();
  }

  /**
   * Returns the German label of an event type.
   *
   * @param string $type
   *   The event type key.
   *
   * @return string
   *   The label.
   */
  private function getTypeLabel(string $type): string {
    if ($this->typeLabels === NULL) {
      $this->typeLabels = [];
      $field = $this->entityTypeManager->getStorage('field_config')
        ->load('node.event.field_event_type');
      if ($field !== NULL) {
        $allowed = $field->getSetting('allowed_values');
        if (is_array($allowed)) {
          foreach ($allowed as $key => $label) {
            if (is_string($key) && is_string($label)) {
              $this->typeLabels[$key] = $label;
            }
          }
        }
      }
    }
    return $this->typeLabels[$type] ?? $type;
  }

  /**
   * Returns the number of days to include in the past.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return int
   *   The number of days, between 0 and 90.
   */
  private function getPastDays(Request $request): int {
    $parameters = $request->query->all();
    $past = $parameters['past'] ?? NULL;
    if (!is_scalar($past) || !is_numeric($past)) {
      return self::DEFAULT_PAST_DAYS;
    }
    return max(0, min(self::MAX_PAST_DAYS, (int) $past));
  }

}
