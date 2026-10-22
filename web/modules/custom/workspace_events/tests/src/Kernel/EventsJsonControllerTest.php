<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\Controller\EventsJsonController;
use Drupal\workspace_events\EventFilterFactory;
use Drupal\workspace_events\EventOccurrenceRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the JSON endpoint of the calendar.
 */
#[CoversClass(EventsJsonController::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class EventsJsonControllerTest extends EventKernelTestBase {

  /**
   * The JSON controller.
   */
  private EventsJsonController $controller;

  /**
   * The course node ID.
   */
  private int $courseId;

  /**
   * The location A term ID.
   */
  private int $locationA;

  /**
   * The location B term ID.
   */
  private int $locationB;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $repository = $this->container->get(EventOccurrenceRepository::class);
    \assert($repository instanceof EventOccurrenceRepository);
    $filter_factory = $this->container->get(EventFilterFactory::class);
    \assert($filter_factory instanceof EventFilterFactory);
    $this->controller = new EventsJsonController($repository, $filter_factory);

    $location_a = $this->createLocation('Bootshaus Stralsund');
    $location_b = $this->createLocation('Treff ZuverSICht');
    $this->locationA = (int) $location_a->id();
    $this->locationB = (int) $location_b->id();

    $start = $this->date('now +2 days', '18:15');
    $weekdays = [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU'];
    $course = $this->createCourse([
      'title' => 'Anfänger',
      'field_location' => $this->locationA,
      'field_when' => $this->recurringDeltas(
        $start,
        $start->setTime(19, 15),
        'WEEKLY',
        'INTERVAL=1;BYDAY=' . $weekdays[(int) $start->format('N')],
        'COUNT=4',
        'course',
      ),
    ]);
    $this->courseId = (int) $course->id();

    $this->createEvent([
      'title' => 'Salsa-Nacht am Sund',
      'field_location' => $this->locationA,
      'field_link' => ['uri' => 'https://www.ballhaus-goldfisch.de/', 'title' => 'ballhaus goldfisch'],
      'field_when' => [
        $this->dateDelta($this->date('now +3 days', '18:00'), $this->date('now +3 days', '22:00')),
      ],
    ]);

    $this->createEvent([
      'title' => 'Tanzabend im Freien',
      'field_location' => $this->locationB,
      'field_when' => [
        $this->allDayDelta($this->date('now +10 days')),
      ],
    ]);

    $this->createEvent([
      'title' => 'Externe Party',
      'field_organizer' => $this->externalOrganizer,
      'field_location' => $this->locationA,
      'field_when' => [
        $this->dateDelta($this->date('now +5 days', '20:00'), NULL),
      ],
    ]);

    // Unpublished nodes are not part of the calendar.
    $this->createEvent([
      'title' => 'Entwurf',
      'status' => NodeInterface::NOT_PUBLISHED,
      'field_when' => [
        $this->dateDelta($this->date('now +4 days', '18:00'), $this->date('now +4 days', '22:00')),
      ],
    ]);
  }

  /**
   * Tests the event items of a range.
   */
  public function testEvents(): void {
    $items = $this->items($this->range());
    self::assertCount(7, $items);

    $event = $this->findByTitle($items, 'Salsa-Nacht am Sund');
    $start = $this->date('now +3 days', '18:00');
    self::assertSame('Salsa-Nacht am Sund', $event['title']);
    self::assertMatchesRegularExpression('/^\d+-\d+-\d+$/', $event['id']);
    self::assertSame($start->format(\DateTimeInterface::ATOM), $event['start']);
    self::assertSame($start->setTime(22, 0)->format(\DateTimeInterface::ATOM), $event['end']);
    self::assertFalse($event['allDay']);
    self::assertSame('https://www.ballhaus-goldfisch.de/', $event['url']);
    self::assertSame(['is-event'], $event['classNames']);
    self::assertSame('Bootshaus Stralsund', $event['extendedProps']['location']);
    self::assertSame('Salsa am Sund', $event['extendedProps']['organizer']);
    self::assertFalse($event['extendedProps']['course']);

    $open_end = $this->findByTitle($items, 'Externe Party');
    self::assertNull($open_end['end']);
    self::assertSame('Sonstige', $open_end['extendedProps']['organizer']);
  }

  /**
   * Tests the all-day date format.
   */
  public function testAllDayEvent(): void {
    $items = $this->items($this->range());
    $all_day = $this->findByTitle($items, 'Tanzabend im Freien');
    $day = $this->date('now +10 days');

    self::assertTrue($all_day['allDay']);
    self::assertSame($day->format('Y-m-d'), $all_day['start']);
    // FullCalendar expects an exclusive end date for whole-day events.
    self::assertSame($day->modify('+1 day')->format('Y-m-d'), $all_day['end']);
    self::assertSame('Treff ZuverSICht', $all_day['extendedProps']['location']);
  }

  /**
   * Tests that a recurring course yields one item per occurrence.
   */
  public function testRecurringCourse(): void {
    $items = $this->items($this->range() + ['type' => 'course']);
    self::assertCount(4, $items);

    $starts = [];
    foreach ($items as $item) {
      self::assertSame('Anfänger', $item['title']);
      self::assertSame(['is-course'], $item['classNames']);
      self::assertTrue($item['extendedProps']['course']);
      self::assertNull($item['extendedProps']['organizer']);
      self::assertSame('Bootshaus Stralsund', $item['extendedProps']['location']);
      $starts[] = $item['start'];
    }
    self::assertCount(4, array_unique($starts));
  }

  /**
   * Tests the filter query arguments.
   */
  public function testFilters(): void {
    $range = $this->range();

    // An organizer selection restricts the result to its events.
    $items = $this->items($range + ['organizer' => (string) $this->externalOrganizer]);
    self::assertSame(['Externe Party'], array_column($items, 'title'));

    $items = $this->items($range + ['organizer' => (string) $this->salsaAmSund]);
    self::assertSame(
      ['Salsa-Nacht am Sund', 'Tanzabend im Freien'],
      array_column($items, 'title'),
    );

    // A location filters events and courses.
    $items = $this->items($range + ['location' => (string) $this->locationA]);
    self::assertCount(6, $items);
    foreach ($items as $item) {
      self::assertSame('Bootshaus Stralsund', $item['extendedProps']['location']);
    }

    // The legacy type selection controls the bundles.
    $items = $this->items($range + ['type' => 'party,external_party']);
    self::assertSame(
      ['Salsa-Nacht am Sund', 'Externe Party', 'Tanzabend im Freien'],
      array_column($items, 'title'),
    );

    $items = $this->items($range + ['course' => (string) $this->courseId]);
    self::assertCount(4, $items);
    foreach ($items as $item) {
      self::assertSame('Anfänger', $item['title']);
    }

    // Courses at another location do not exist.
    self::assertSame([], $this->items($range + ['type' => 'course', 'location' => (string) $this->locationB]));
  }

  /**
   * Tests that invalid ranges are rejected.
   */
  public function testInvalidRanges(): void {
    $start = $this->date('now');
    $end = $this->date('now +10 days');
    $invalid = [
      [],
      ['start' => $start->format(\DateTimeInterface::ATOM)],
      ['end' => $end->format(\DateTimeInterface::ATOM)],
      ['start' => 'gestern', 'end' => $end->format(\DateTimeInterface::ATOM)],
      ['start' => $start->format('Y-m-d'), 'end' => $start->format('Y-m-d')],
      [
        'start' => $start->format('Y-m-d'),
        'end' => $start->modify('+101 days')->format('Y-m-d'),
      ],
    ];

    foreach ($invalid as $query) {
      $response = $this->request($query);
      self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), json_encode($query, JSON_THROW_ON_ERROR));
      $body = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
      self::assertArrayHasKey('error', $body);
    }

    // Exactly 100 days are allowed.
    $response = $this->request([
      'start' => $start->format('Y-m-d'),
      'end' => $start->modify('+100 days')->format('Y-m-d'),
    ]);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
  }

  /**
   * Tests the response cache metadata.
   */
  public function testCacheMetadata(): void {
    $response = $this->request($this->range());
    self::assertInstanceOf(CacheableJsonResponse::class, $response);

    $cacheability = $response->getCacheableMetadata();
    foreach ([
      'url.query_args:start',
      'url.query_args:end',
      'url.query_args:organizer',
      'url.query_args:location',
      'url.query_args:course',
      'url.query_args:type',
    ] as $context) {
      self::assertContains($context, $cacheability->getCacheContexts());
    }
    foreach ([
      'node_list:event',
      'node_list:course',
      'taxonomy_term_list:location',
      'taxonomy_term_list:organizer',
      'taxonomy_term:' . $this->locationA,
      'taxonomy_term:' . $this->locationB,
    ] as $tag) {
      self::assertContains($tag, $cacheability->getCacheTags());
    }

    // The requested window is absolute, so the response does not expire.
    self::assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge());
  }

  /**
   * Returns a range that covers the test fixtures.
   *
   * @return array<string, string>
   *   The start and end query arguments.
   */
  private function range(): array {
    return [
      'start' => $this->date('now -1 day')->format(\DateTimeInterface::ATOM),
      'end' => $this->date('now +30 days')->format(\DateTimeInterface::ATOM),
    ];
  }

  /**
   * Requests the endpoint and returns the decoded items.
   *
   * @param array<string, string> $query
   *   The query arguments.
   *
   * @return array<int, array<string, mixed>>
   *   The decoded events.
   */
  private function items(array $query): array {
    $response = $this->request($query);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $items = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertIsArray($items);
    return array_values($items);
  }

  /**
   * Performs a request against the controller.
   *
   * @param array<string, string> $query
   *   The query arguments.
   */
  private function request(array $query): Response {
    return $this->controller->__invoke(
      Request::create('https://salsa-am-sund.localhost/termine.json', 'GET', $query),
    );
  }

  /**
   * Finds an item by title.
   *
   * @param array<int, array<string, mixed>> $items
   *   The decoded events.
   * @param string $title
   *   The title.
   *
   * @return array<string, mixed>
   *   The item.
   */
  private function findByTitle(array $items, string $title): array {
    foreach ($items as $item) {
      if ($item['title'] === $title) {
        return $item;
      }
    }
    self::fail('No event with title ' . $title);
  }

}
