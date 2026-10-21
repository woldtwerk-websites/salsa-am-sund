<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Unit;

use Drupal\workspace_events\EventFilter;
use Drupal\workspace_events\EventFilterFactory;
use Drupal\workspace_events\OrganizerResolver;
use Drupal\workspace_events\OrganizerLookupInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * Tests the event filter and its query parsing.
 */
#[CoversClass(EventFilter::class)]
#[CoversClass(EventFilterFactory::class)]
#[Group('workspace_events')]
final class EventFilterTest extends UnitTestCase {

  /**
   * The organizer term ID of the "Salsa am Sund" term used by the stub.
   */
  public const int SALSA_ID = 11;

  /**
   * The organizer term ID of the "Extern" term used by the stub.
   */
  public const int EXTERNAL_ID = 12;

  /**
   * Provides query parameters and the expected filter values.
   *
   * @return iterable<string, array{0: array<string, mixed>|InputBag, 1: array<string, mixed>}>
   *   The test cases: query parameters and the expected filter properties.
   */
  public static function queryProvider(): iterable {
    yield 'no parameters' => [[], [
      'includeEvents' => TRUE,
      'organizerIds' => [],
      'includeCourses' => TRUE,
      'courseIds' => [],
      'locationIds' => [],
    ]];
    yield 'comma separated legacy types' => [
      ['type' => 'course,party'],
      [
        'includeEvents' => TRUE,
        'organizerIds' => [self::SALSA_ID],
        'includeCourses' => TRUE,
        'courseIds' => [],
        'locationIds' => [],
      ],
    ];
    yield 'legacy types are trimmed and whitelisted' => [
      ['type' => ' course , external_party ,unknown'],
      [
        'includeEvents' => TRUE,
        'organizerIds' => [self::EXTERNAL_ID],
        'includeCourses' => TRUE,
        'courseIds' => [],
        'locationIds' => [],
      ],
    ];
    yield 'repeated legacy type parameters' => [
      ['type' => ['course', 'party,external_party']],
      [
        'includeEvents' => TRUE,
        'organizerIds' => [self::SALSA_ID, self::EXTERNAL_ID],
        'includeCourses' => TRUE,
        'courseIds' => [],
        'locationIds' => [],
      ],
    ];
    yield 'duplicate legacy types are removed' => [
      ['type' => 'party,party'],
      [
        'includeEvents' => TRUE,
        'organizerIds' => [self::SALSA_ID],
        'includeCourses' => FALSE,
        'courseIds' => [],
        'locationIds' => [],
      ],
    ];
    yield 'unknown legacy type keeps everything' => [
      ['type' => 'unknown'],
      [
        'includeEvents' => TRUE,
        'organizerIds' => [],
        'includeCourses' => TRUE,
        'courseIds' => [],
        'locationIds' => [],
      ],
    ];
    yield 'single organizer' => [
      ['organizer' => '3'],
      [
        'includeEvents' => TRUE,
        'organizerIds' => [3],
        'includeCourses' => FALSE,
        'courseIds' => [],
        'locationIds' => [],
      ],
    ];
    yield 'multiple organizers' => [
      ['organizer' => '3, 7'],
      [
        'includeEvents' => TRUE,
        'organizerIds' => [3, 7],
        'includeCourses' => FALSE,
        'courseIds' => [],
        'locationIds' => [],
      ],
    ];
    yield 'repeated organizer parameters' => [
      ['organizer' => ['3', '7,11']],
      [
        'includeEvents' => TRUE,
        'organizerIds' => [3, 7, 11],
        'includeCourses' => FALSE,
        'courseIds' => [],
        'locationIds' => [],
      ],
    ];
    yield 'invalid organizers are ignored' => [
      ['organizer' => '0,-3,abc,3.5,8'],
      [
        'includeEvents' => TRUE,
        'organizerIds' => [8],
        'includeCourses' => FALSE,
        'courseIds' => [],
        'locationIds' => [],
      ],
    ];
    yield 'single location keeps both kinds' => [
      ['location' => '3'],
      [
        'includeEvents' => TRUE,
        'organizerIds' => [],
        'includeCourses' => TRUE,
        'courseIds' => [],
        'locationIds' => [3],
      ],
    ];
    yield 'multiple locations' => [
      ['location' => '3, 7'],
      [
        'includeEvents' => TRUE,
        'organizerIds' => [],
        'includeCourses' => TRUE,
        'courseIds' => [],
        'locationIds' => [3, 7],
      ],
    ];
    yield 'repeated location parameters' => [
      ['location' => ['3', '7,11']],
      [
        'includeEvents' => TRUE,
        'organizerIds' => [],
        'includeCourses' => TRUE,
        'courseIds' => [],
        'locationIds' => [3, 7, 11],
      ],
    ];
    yield 'invalid locations are ignored' => [
      ['location' => '0,-3,abc,3.5,8'],
      [
        'includeEvents' => TRUE,
        'organizerIds' => [],
        'includeCourses' => TRUE,
        'courseIds' => [],
        'locationIds' => [8],
      ],
    ];
    yield 'single course excludes events' => [
      ['course' => '2'],
      [
        'includeEvents' => FALSE,
        'organizerIds' => [],
        'includeCourses' => TRUE,
        'courseIds' => [2],
        'locationIds' => [],
      ],
    ];
    yield 'multiple courses' => [
      ['course' => '2, 4'],
      [
        'includeEvents' => FALSE,
        'organizerIds' => [],
        'includeCourses' => TRUE,
        'courseIds' => [2, 4],
        'locationIds' => [],
      ],
    ];
    yield 'repeated course parameters' => [
      ['course' => ['2', '4,5']],
      [
        'includeEvents' => FALSE,
        'organizerIds' => [],
        'includeCourses' => TRUE,
        'courseIds' => [2, 4, 5],
        'locationIds' => [],
      ],
    ];
    yield 'invalid courses are ignored' => [
      ['course' => '0,-3,abc,2.5,8'],
      [
        'includeEvents' => FALSE,
        'organizerIds' => [],
        'includeCourses' => TRUE,
        'courseIds' => [8],
        'locationIds' => [],
      ],
    ];
    yield 'duplicate courses are removed' => [
      ['course' => '5,5'],
      [
        'includeEvents' => FALSE,
        'organizerIds' => [],
        'includeCourses' => TRUE,
        'courseIds' => [5],
        'locationIds' => [],
      ],
    ];
    yield 'courses are added to the legacy party types' => [
      ['type' => 'party,external_party', 'course' => '2'],
      [
        'includeEvents' => TRUE,
        'organizerIds' => [self::SALSA_ID, self::EXTERNAL_ID],
        'includeCourses' => TRUE,
        'courseIds' => [2],
        'locationIds' => [],
      ],
    ];
    yield 'an explicit legacy course type is not duplicated' => [
      ['type' => 'course,party', 'course' => '2'],
      [
        'includeEvents' => TRUE,
        'organizerIds' => [self::SALSA_ID],
        'includeCourses' => TRUE,
        'courseIds' => [2],
        'locationIds' => [],
      ],
    ];
    yield 'organizers and courses are combined' => [
      ['organizer' => '3', 'course' => '2'],
      [
        'includeEvents' => TRUE,
        'organizerIds' => [3],
        'includeCourses' => TRUE,
        'courseIds' => [2],
        'locationIds' => [],
      ],
    ];
    yield 'a legacy course type plus organizer' => [
      ['type' => 'course', 'organizer' => '3'],
      [
        'includeEvents' => TRUE,
        'organizerIds' => [3],
        'includeCourses' => TRUE,
        'courseIds' => [],
        'locationIds' => [],
      ],
    ];
    yield 'legacy type is not a scalar' => [
      ['type' => [['course'], ['party']]],
      [
        'includeEvents' => TRUE,
        'organizerIds' => [self::SALSA_ID],
        'includeCourses' => TRUE,
        'courseIds' => [],
        'locationIds' => [],
      ],
    ];
    yield 'InputBag with scalar values' => [
      new InputBag(['type' => 'course', 'location' => '2', 'course' => '7']),
      [
        'includeEvents' => FALSE,
        'organizerIds' => [],
        'includeCourses' => TRUE,
        'courseIds' => [7],
        'locationIds' => [2],
      ],
    ];
    yield 'InputBag with array values' => [
      new InputBag(['type' => ['course', 'party'], 'location' => ['2', '4'], 'course' => ['7', '9']]),
      [
        'includeEvents' => TRUE,
        'organizerIds' => [self::SALSA_ID],
        'includeCourses' => TRUE,
        'courseIds' => [7, 9],
        'locationIds' => [2, 4],
      ],
    ];
  }

  /**
   * Tests parsing query parameters.
   *
   * @param array<string, mixed>|\Symfony\Component\HttpFoundation\InputBag $query
   *   The query parameters.
   * @param array<string, mixed> $expected
   *   The expected filter properties.
   */
  #[DataProvider('queryProvider')]
  public function testFromQuery(array|InputBag $query, array $expected): void {
    $filter = $this->createFactory()->fromQuery($query);
    self::assertSame($expected['includeEvents'], $filter->includeEvents);
    self::assertSame($expected['organizerIds'], $filter->organizerIds);
    self::assertSame($expected['includeCourses'], $filter->includeCourses);
    self::assertSame($expected['courseIds'], $filter->courseIds);
    self::assertSame($expected['locationIds'], $filter->locationIds);
  }

  /**
   * Tests legacy types when the organizer terms are missing.
   */
  public function testLegacyTypeWithoutTermsIsIgnored(): void {
    $lookup = new class implements OrganizerLookupInterface {

      /**
       * {@inheritdoc}
       */
      public function id(string $uuid): ?int {
        return NULL;
      }

      /**
       * {@inheritdoc}
       */
      public function termIds(): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function ids(): array {
        return [];
      }

    };
    $filter = (new EventFilterFactory($lookup))->fromQuery(['type' => 'party']);
    // Without the term the legacy key still selects events, but no organizer.
    self::assertTrue($filter->includeEvents);
    self::assertFalse($filter->includeCourses);
    self::assertSame([], $filter->organizerIds);
  }

  /**
   * Tests the empty check and query serialization.
   */
  public function testEmptyAndQuery(): void {
    $filter = new EventFilter();
    self::assertTrue($filter->isEmpty());
    self::assertSame([], $filter->toQuery());

    $filter = new EventFilter(locationIds: [3, 7]);
    self::assertFalse($filter->isEmpty());
    self::assertSame(['location' => '3,7'], $filter->toQuery());

    $filter = new EventFilter(includeEvents: TRUE, organizerIds: [3, 7], includeCourses: TRUE);
    self::assertFalse($filter->isEmpty());
    self::assertSame(['organizer' => '3,7'], $filter->toQuery());

    // Selecting individual courses is not an empty filter either.
    $filter = EventFilter::coursesOnly([2, 4]);
    self::assertFalse($filter->isEmpty());
    self::assertSame([
      'course' => '2,4',
      'type' => 'course',
    ], $filter->toQuery());

    // A course-only filter without a course selection still means every
    // course.
    $filter = EventFilter::coursesOnly();
    self::assertFalse($filter->isEmpty());
    self::assertSame(['type' => 'course'], $filter->toQuery());

    self::assertFalse(EventFilter::eventsOnly([3])->isEmpty());
  }

  /**
   * Creates a filter factory with a lookup for both fixed organizer terms.
   *
   * @return \Drupal\workspace_events\EventFilterFactory
   *   The filter factory.
   */
  private function createFactory(): EventFilterFactory {
    $lookup = new class implements OrganizerLookupInterface {

      /**
       * {@inheritdoc}
       */
      public function id(string $uuid): ?int {
        $ids = [
          OrganizerResolver::SALSA_AM_SUND_UUID => EventFilterTest::SALSA_ID,
          OrganizerResolver::EXTERNAL_UUID => EventFilterTest::EXTERNAL_ID,
        ];
        return $ids[$uuid] ?? NULL;
      }

      /**
       * {@inheritdoc}
       */
      public function termIds(): array {
        return [EventFilterTest::SALSA_ID, EventFilterTest::EXTERNAL_ID];
      }

      /**
       * {@inheritdoc}
       */
      public function ids(): array {
        return [
          OrganizerResolver::SALSA_AM_SUND_UUID => EventFilterTest::SALSA_ID,
          OrganizerResolver::EXTERNAL_UUID => EventFilterTest::EXTERNAL_ID,
        ];
      }

    };
    return new EventFilterFactory($lookup);
  }

}
