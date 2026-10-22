<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\workspace_events\Hook\CoursePreprocessHooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the data of the course detail page.
 */
#[CoversClass(CoursePreprocessHooks::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class CoursePreprocessHooksTest extends EventKernelTestBase {

  /**
   * The course preprocess hooks.
   */
  private CoursePreprocessHooks $hooks;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $hooks = $this->container->get(CoursePreprocessHooks::class);
    \assert($hooks instanceof CoursePreprocessHooks);
    $this->hooks = $hooks;
  }

  /**
   * Tests the prepared data of a full course page.
   */
  public function testPreprocessFullCourse(): void {
    $start = $this->date('next monday', '19:30');
    $end = $this->date('next monday', '20:30');
    $deltas = $this->recurringDeltas(
      $start,
      $end,
      'WEEKLY',
      'INTERVAL=1;BYDAY=' . strtoupper(substr($start->format('D'), 0, 2)),
      '',
      'course',
    );

    $location = $this->createLocation(
      'Bootshaus Stralsund',
      "Friedrich-Naumann-Straße 5\n18435 Stralsund",
      'https://www.openstreetmap.org/search?query=Friedrich-Naumann-Stra%C3%9Fe%205',
    );
    $course = $this->createCourse([
      'title' => 'Leicht Fortgeschrittene',
      'field_level' => 'Level 2',
      'field_location' => $location->id(),
      'field_link' => [
        'uri' => 'https://example.com/kurs',
        'title' => 'Kurs buchen',
      ],
      'body' => [
        'value' => '<p>Neue Figuren und Elemente für Fortgeschrittene.</p>',
        'format' => 'plain_text',
      ],
      'field_when' => $deltas,
    ]);

    $variables = [
      'node' => $course,
      'view_mode' => 'full',
    ];
    $this->hooks->preprocessNode($variables);

    $date_formatter = $this->container->get('date.formatter');
    \assert($date_formatter instanceof DateFormatterInterface);
    $weekday = $date_formatter->format(
      $start->getTimestamp(),
      'custom',
      'l',
      $start->getTimezone()->getName(),
    );

    self::assertSame('Level 2', $variables['course_level']);
    self::assertSame($weekday . ' · 19:30 – 20:30', $variables['course_schedule']);

    $dates = $variables['course_next_dates'];
    \assert(is_array($dates));
    self::assertCount(6, $dates);

    $expected_dates = [];
    for ($week = 0; $week < 6; $week++) {
      $expected_dates[] = $start->modify('+' . $week . ' weeks')->format('Y-m-d');
    }
    self::assertSame($expected_dates, array_column($dates, 'date_iso'));
    foreach ($dates as $index => $date) {
      \assert(is_array($date));
      self::assertSame($weekday, $date['weekday']);
      self::assertSame('19:30 – 20:30', $date['time']);
      self::assertSame($start->modify('+' . $index . ' weeks')->format('d.m.Y'), $date['date']);
    }

    self::assertSame(
      '/termine.ics?course=' . $course->id(),
      $variables['course_ical_url'],
    );
    self::assertSame('/salsa-kurse', $variables['course_overview_url']);

    $location_data = $variables['course_location'];
    \assert(is_array($location_data));
    self::assertSame('Bootshaus Stralsund', $location_data['name']);
    self::assertSame("Friedrich-Naumann-Straße 5\n18435 Stralsund", $location_data['address']);
    self::assertSame(
      'https://www.openstreetmap.org/search?query=Friedrich-Naumann-Stra%C3%9Fe%205',
      $location_data['map_url'],
    );

    $link = $variables['course_link'];
    \assert(is_array($link));
    self::assertSame('Kurs buchen', $link['label']);
    self::assertSame('https://example.com/kurs', $link['href']);

    $cache = $variables['#cache'];
    \assert(is_array($cache));
    self::assertContains('node:' . $course->id(), $cache['tags']);
    self::assertContains('taxonomy_term:' . $location->id(), $cache['tags']);
    self::assertContains('config:filter.format.plain_text', $cache['tags']);

    // The page may be cached until the next date has ended.
    $request_time = $this->container->get('datetime.time')->getRequestTime();
    self::assertSame($deltas[0]['end_value'] - $request_time, $cache['max-age']);
  }

  /**
   * Tests that only full course pages are prepared.
   */
  public function testOtherBundlesAndViewModesAreIgnored(): void {
    $event = $this->createEvent([
      'title' => 'Tanzabend',
      'field_when' => [
        $this->dateDelta($this->date('now +1 day', '20:00'), NULL),
      ],
    ]);
    $variables = [
      'node' => $event,
      'view_mode' => 'full',
    ];
    $this->hooks->preprocessNode($variables);
    self::assertArrayNotHasKey('course_next_dates', $variables);

    $course = $this->createCourse([
      'title' => 'Anfänger',
      'field_when' => [
        $this->dateDelta($this->date('now +2 days', '18:15'), $this->date('now +2 days', '19:15')),
      ],
    ]);
    $variables = [
      'node' => $course,
      'view_mode' => 'teaser',
    ];
    $this->hooks->preprocessNode($variables);
    self::assertArrayNotHasKey('course_next_dates', $variables);
  }

}
