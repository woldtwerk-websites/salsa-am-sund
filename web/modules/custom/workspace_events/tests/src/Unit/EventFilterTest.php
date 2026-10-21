<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Unit;

use Drupal\workspace_events\EventFilter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * Tests the event filter query parsing.
 */
#[CoversClass(EventFilter::class)]
#[Group('workspace_events')]
final class EventFilterTest extends UnitTestCase {

  /**
   * Provides query parameters and the expected filter values.
   *
   * @return iterable<string, array{0: array<string, mixed>|InputBag, 1: string[], 2: int[]}>
   *   The test cases.
   */
  public static function queryProvider(): iterable {
    yield 'no parameters' => [[], [], []];
    yield 'comma separated types' => [
      ['type' => 'course,party'],
      ['course', 'party'],
      [],
    ];
    yield 'types are trimmed and whitelisted' => [
      ['type' => ' course , external_party ,unknown'],
      ['course', 'external_party'],
      [],
    ];
    yield 'repeated type parameters' => [
      ['type' => ['course', 'party,external_party']],
      ['course', 'party', 'external_party'],
      [],
    ];
    yield 'duplicate types are removed' => [
      ['type' => 'party,party'],
      ['party'],
      [],
    ];
    yield 'single location' => [
      ['location' => '3'],
      [],
      [3],
    ];
    yield 'multiple locations' => [
      ['location' => '3, 7'],
      [],
      [3, 7],
    ];
    yield 'repeated location parameters' => [
      ['location' => ['3', '7,11']],
      [],
      [3, 7, 11],
    ];
    yield 'invalid locations are ignored' => [
      ['location' => '0,-3,abc,3.5,8'],
      [],
      [8],
    ];
    yield 'duplicate locations are removed' => [
      ['location' => '5,5'],
      [],
      [5],
    ];
    yield 'type is not a scalar' => [
      ['type' => [['course'], ['party']]],
      ['course', 'party'],
      [],
    ];
    yield 'InputBag with scalar values' => [
      new InputBag(['type' => 'course', 'location' => '2']),
      ['course'],
      [2],
    ];
    yield 'InputBag with array values' => [
      new InputBag(['type' => ['course', 'party'], 'location' => ['2', '4']]),
      ['course', 'party'],
      [2, 4],
    ];
  }

  /**
   * Tests parsing query parameters.
   *
   * @param array<string, mixed>|\Symfony\Component\HttpFoundation\InputBag $query
   *   The query parameters.
   * @param string[] $types
   *   The expected types.
   * @param int[] $locations
   *   The expected location IDs.
   */
  #[DataProvider('queryProvider')]
  public function testFromQuery(array|InputBag $query, array $types, array $locations): void {
    $filter = EventFilter::fromQuery($query);
    self::assertSame($types, $filter->types);
    self::assertSame($locations, $filter->locationIds);
  }

  /**
   * Tests the empty check and query serialization.
   */
  public function testEmptyAndQuery(): void {
    $filter = new EventFilter();
    self::assertTrue($filter->isEmpty());
    self::assertSame([], $filter->toQuery());

    $filter = new EventFilter(['course', 'party'], [3, 7]);
    self::assertFalse($filter->isEmpty());
    self::assertSame([
      'type' => 'course,party',
      'location' => '3,7',
    ], $filter->toQuery());
  }

}
