<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\node\NodeInterface;
use Drupal\workspace_events\EventFilter;
use Drupal\workspace_events\EventOccurrenceRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the event occurrence repository.
 */
#[CoversClass(EventOccurrenceRepository::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class EventOccurrenceRepositoryTest extends EventKernelTestBase {

  /**
   * The occurrence repository.
   */
  private EventOccurrenceRepository $repository;

  /**
   * The location A.
   */
  private int $locationA;

  /**
   * The location B.
   */
  private int $locationB;

  /**
   * The weekly course node ID.
   */
  private int $courseId;

  /**
   * The party node ID.
   */
  private int $partyId;

  /**
   * The all-day event node ID.
   */
  private int $allDayId;

  /**
   * The event without a known end node ID.
   */
  private int $openEndId;

  /**
   * The unpublished event node ID.
   */
  private int $unpublishedId;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->repository = $this->container->get(EventOccurrenceRepository::class);

    $location_a = $this->createLocation('Bootshaus Stralsund', "Friedrich-Naumann-Straße 5\n18435 Stralsund");
    $location_b = $this->createLocation('Treff ZuverSICht');
    $this->locationA = (int) $location_a->id();
    $this->locationB = (int) $location_b->id();

    $course = $this->createEvent([
      'title' => 'Anfänger',
      'field_event_type' => 'course',
      'field_location' => $this->locationA,
      'field_level' => 'Level 0',
      'field_when' => $this->recurringDeltas(
        $this->date('2026-10-05', '18:15'),
        $this->date('2026-10-05', '19:15'),
        'WEEKLY',
        'INTERVAL=1;BYDAY=MO',
      ),
    ]);
    $this->courseId = (int) $course->id();

    $party = $this->createEvent([
      'title' => 'Tanzabend',
      'field_event_type' => 'party',
      'field_location' => $this->locationA,
      'field_when' => [
        $this->dateDelta($this->date('2026-10-26', '18:00'), $this->date('2026-10-26', '22:00')),
      ],
    ]);
    $this->partyId = (int) $party->id();

    $all_day = $this->createEvent([
      'title' => 'Salsa-Party',
      'field_event_type' => 'party',
      'field_location' => $this->locationB,
      'field_when' => [
        $this->allDayDelta($this->date('2026-11-20')),
      ],
    ]);
    $this->allDayId = (int) $all_day->id();

    $open_end = $this->createEvent([
      'title' => 'Salsa im ballhaus goldfisch',
      'field_event_type' => 'external_party',
      'field_location' => $this->locationB,
      'field_when' => [
        $this->dateDelta($this->date('2026-10-09', '20:00'), NULL),
      ],
    ]);
    $this->openEndId = (int) $open_end->id();

    $unpublished = $this->createEvent([
      'title' => 'Entwurf',
      'status' => NodeInterface::NOT_PUBLISHED,
      'field_event_type' => 'party',
      'field_location' => $this->locationA,
      'field_when' => [
        $this->dateDelta($this->date('2026-10-10', '20:00'), $this->date('2026-10-10', '22:00')),
      ],
    ]);
    $this->unpublishedId = (int) $unpublished->id();
  }

  /**
   * Tests finding occurrences in a range.
   */
  public function testFindOccurrences(): void {
    $occurrences = $this->repository->findOccurrences(
      new EventFilter(),
      $this->date('2026-10-01'),
      $this->date('2026-10-31', '23:59'),
    );

    self::assertCount(6, $occurrences);
    $starts = array_map(static fn ($occurrence): int => $occurrence->start->getTimestamp(), $occurrences);
    $sorted = $starts;
    sort($sorted);
    self::assertSame($sorted, $starts);

    $node_ids = array_map(static fn ($occurrence): int => (int) $occurrence->node->id(), $occurrences);
    self::assertNotContains($this->unpublishedId, $node_ids);
    self::assertNotContains($this->allDayId, $node_ids);

    // The recurring course yields four occurrences in October.
    self::assertCount(4, array_filter($node_ids, fn (int $id): bool => $id === $this->courseId));

    // The 18:00 party is sorted before the 18:15 course on the same day.
    $last_day = array_values(array_filter($occurrences, static fn ($occurrence): bool => $occurrence->start->format('Y-m-d') === '2026-10-26'));
    self::assertCount(2, $last_day);
    self::assertSame('party', $last_day[0]->type);
    self::assertSame('course', $last_day[1]->type);

    // An event without an end has no end value.
    $open_end = array_values(array_filter($occurrences, fn ($occurrence): bool => (int) $occurrence->node->id() === $this->openEndId));
    self::assertCount(1, $open_end);
    self::assertNull($open_end[0]->end);
    self::assertFalse($open_end[0]->allDay);
  }

  /**
   * Tests filtering by event type.
   */
  public function testFilterByType(): void {
    $filter = EventFilter::fromQuery(['type' => 'party']);
    $occurrences = $this->repository->findOccurrences(
      $filter,
      $this->date('2026-10-01'),
      $this->date('2026-12-01'),
    );
    self::assertCount(2, $occurrences);
    self::assertSame('party', $occurrences[0]->type);
    self::assertTrue($occurrences[1]->allDay);
    self::assertSame($this->allDayId, (int) $occurrences[1]->node->id());

    $occurrences = $this->repository->findOccurrences(
      new EventFilter(['external_party']),
      $this->date('2026-10-01'),
      $this->date('2026-12-01'),
    );
    self::assertCount(1, $occurrences);
    self::assertSame($this->openEndId, (int) $occurrences[0]->node->id());
  }

  /**
   * Tests filtering by location.
   */
  public function testFilterByLocation(): void {
    $occurrences = $this->repository->findOccurrences(
      new EventFilter([], [$this->locationA]),
      $this->date('2026-10-01'),
      $this->date('2026-10-31', '23:59'),
    );
    self::assertCount(5, $occurrences);
    foreach ($occurrences as $occurrence) {
      self::assertSame($this->locationA, (int) $occurrence->location?->id());
    }

    $occurrences = $this->repository->findOccurrences(
      new EventFilter([], [$this->locationB]),
      $this->date('2026-10-01'),
      $this->date('2026-10-31', '23:59'),
    );
    self::assertCount(1, $occurrences);
    self::assertSame($this->openEndId, (int) $occurrences[0]->node->id());
  }

  /**
   * Tests the limit and the time window boundaries.
   */
  public function testLimitAndWindow(): void {
    $occurrences = $this->repository->findOccurrences(
      new EventFilter(),
      $this->date('2026-10-01'),
      $this->date('2026-10-31', '23:59'),
      2,
    );
    self::assertCount(2, $occurrences);
    self::assertSame('2026-10-05', $occurrences[0]->start->format('Y-m-d'));
    self::assertSame('2026-10-09', $occurrences[1]->start->format('Y-m-d'));

    // The weekly course is not visible when the range starts after it.
    $occurrences = $this->repository->findOccurrences(
      new EventFilter(['course']),
      $this->date('2026-11-01'),
      $this->date('2026-11-30', '23:59'),
    );
    self::assertCount(5, $occurrences);
    self::assertSame('2026-11-02', $occurrences[0]->start->format('Y-m-d'));

    // Reversed ranges produce no results.
    self::assertSame([], $this->repository->findOccurrences(
      new EventFilter(),
      $this->date('2026-12-01'),
      $this->date('2026-11-01'),
    ));
  }

}
