<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\workspace_events\Hook\DetailPagePreprocessHooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the data of the course and event detail pages.
 */
#[CoversClass(DetailPagePreprocessHooks::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class DetailPagePreprocessHooksTest extends EventKernelTestBase {

  /**
   * The detail page preprocess hooks.
   */
  private DetailPagePreprocessHooks $hooks;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $hooks = $this->container->get(DetailPagePreprocessHooks::class);
    \assert($hooks instanceof DetailPagePreprocessHooks);
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
    self::assertSame(
      '/termine/' . $course->id() . '/0/ical',
      $dates[0]['ical_url'],
    );
    foreach ($dates as $index => $date) {
      \assert(is_array($date));
      self::assertSame($weekday, $date['weekday']);
      self::assertSame('19:30 – 20:30', $date['time']);
      self::assertSame($start->modify('+' . $index . ' weeks')->format('d.m.Y'), $date['date']);
    }

    self::assertStringStartsWith('webcal://', $variables['course_ical_url']);
    self::assertStringEndsWith(
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
    self::assertContains('url.site', $cache['contexts']);

    // The page may be cached until the next date has ended.
    $request_time = $this->container->get('datetime.time')->getRequestTime();
    self::assertSame($deltas[0]['end_value'] - $request_time, $cache['max-age']);
  }

  /**
   * Tests the prepared data of a full event page.
   */
  public function testPreprocessFullEvent(): void {
    $start = $this->date('next wednesday', '21:00');
    $end = $this->date('next wednesday', '23:30');
    $deltas = $this->recurringDeltas(
      $start,
      $end,
      'WEEKLY',
      'INTERVAL=1;BYDAY=' . strtoupper(substr($start->format('D'), 0, 2)),
      '',
      'event',
    );

    $location = $this->createLocation(
      'Kulturhaus Stralsund',
      "Olof-Palme-Platz 6\n18439 Stralsund",
      'https://www.openstreetmap.org/search?query=Olof-Palme-Platz%206',
    );
    $event = $this->createEvent([
      'title' => 'Salsa Night',
      'field_organizer' => $this->externalOrganizer,
      'field_location' => $location->id(),
      'field_link' => [
        'uri' => 'https://example.com/party',
        'title' => 'Tickets',
      ],
      'body' => [
        'value' => '<p>Jeden Mittwoch Tanzen bis Mitternacht.</p>',
        'format' => 'plain_text',
      ],
      'field_when' => $deltas,
    ]);

    $variables = [
      'node' => $event,
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

    self::assertSame('Sonstige', $variables['event_organizer']);

    $next_date = $variables['event_next_date'];
    \assert(is_array($next_date));
    self::assertSame([
      'date' => $start->format('d.m.Y'),
      'date_iso' => $start->format('Y-m-d'),
      'weekday' => $weekday,
      'time' => '21:00 – 23:30',
      'ical_url' => '/termine/' . $event->id() . '/0/ical',
    ], $next_date);

    $dates = $variables['event_next_dates'];
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
      self::assertSame('21:00 – 23:30', $date['time']);
      self::assertSame($start->modify('+' . $index . ' weeks')->format('d.m.Y'), $date['date']);
    }

    self::assertStringStartsWith('webcal://', $variables['event_ical_url']);
    self::assertStringEndsWith(
      '/termine.ics?organizer=' . $this->externalOrganizer,
      $variables['event_ical_url'],
    );
    self::assertSame('/veranstaltungen', $variables['event_overview_url']);

    $location_data = $variables['event_location'];
    \assert(is_array($location_data));
    self::assertSame('Kulturhaus Stralsund', $location_data['name']);
    self::assertSame("Olof-Palme-Platz 6\n18439 Stralsund", $location_data['address']);
    self::assertSame(
      'https://www.openstreetmap.org/search?query=Olof-Palme-Platz%206',
      $location_data['map_url'],
    );

    $link = $variables['event_link'];
    \assert(is_array($link));
    self::assertSame('Tickets', $link['label']);
    self::assertSame('https://example.com/party', $link['href']);

    self::assertArrayNotHasKey('course_next_dates', $variables);

    $cache = $variables['#cache'];
    \assert(is_array($cache));
    self::assertContains('node:' . $event->id(), $cache['tags']);
    self::assertContains('taxonomy_term:' . $location->id(), $cache['tags']);
    self::assertContains('taxonomy_term:' . $this->externalOrganizer, $cache['tags']);
    self::assertContains('config:filter.format.plain_text', $cache['tags']);
    self::assertContains('url.site', $cache['contexts']);

    // The page may be cached until the next date has ended.
    $request_time = $this->container->get('datetime.time')->getRequestTime();
    self::assertSame($deltas[0]['end_value'] - $request_time, $cache['max-age']);
  }

  /**
   * Tests an event without upcoming dates.
   */
  public function testPreprocessEventWithoutUpcomingDates(): void {
    $event = $this->createEvent([
      'title' => 'Vergangene Party',
      'field_when' => [
        $this->dateDelta($this->date('2020-01-04', '21:00'), $this->date('2020-01-04', '23:30')),
      ],
    ]);

    $variables = [
      'node' => $event,
      'view_mode' => 'full',
    ];
    $this->hooks->preprocessNode($variables);

    self::assertNull($variables['event_next_date']);
    self::assertSame([], $variables['event_next_dates']);
    self::assertStringStartsWith('webcal://', $variables['event_ical_url']);
    self::assertStringEndsWith(
      '/termine.ics?organizer=' . $this->salsaAmSund,
      $variables['event_ical_url'],
    );

    $cache = $variables['#cache'];
    \assert(is_array($cache));
    self::assertSame(3600, $cache['max-age']);
  }

  /**
   * Tests that only full detail pages are prepared.
   */
  public function testOtherViewModesAreIgnored(): void {
    $event = $this->createEvent([
      'title' => 'Tanzabend',
      'field_when' => [
        $this->dateDelta($this->date('now +1 day', '20:00'), NULL),
      ],
    ]);
    $variables = [
      'node' => $event,
      'view_mode' => 'teaser',
    ];
    $this->hooks->preprocessNode($variables);
    self::assertArrayNotHasKey('event_next_dates', $variables);

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
