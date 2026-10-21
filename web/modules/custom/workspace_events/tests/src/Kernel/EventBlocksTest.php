<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\workspace_events\Plugin\Block\CourseScheduleBlock;
use Drupal\workspace_events\Plugin\Block\UpcomingEventsBlock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the data selection of the event blocks.
 */
#[CoversClass(UpcomingEventsBlock::class)]
#[CoversClass(CourseScheduleBlock::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class EventBlocksTest extends EventKernelTestBase {

  /**
   * The block plugin manager.
   */
  private BlockManagerInterface $blockManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->blockManager = $this->container->get('plugin.manager.block');
  }

  /**
   * Tests the upcoming events block.
   */
  public function testUpcomingEventsBlock(): void {
    $location = $this->createLocation('Bootshaus Stralsund', "Friedrich-Naumann-Straße 5\n18435 Stralsund");
    $this->createEvent([
      'title' => 'Salsa im ballhaus goldfisch',
      'field_event_type' => 'external_party',
      'field_location' => $location->id(),
      'field_when' => [
        $this->dateDelta($this->date('now +1 day', '20:00'), NULL),
      ],
    ]);
    $this->createEvent([
      'title' => 'Anfänger',
      'field_event_type' => 'course',
      'field_when' => [
        $this->dateDelta($this->date('now +2 days', '18:15'), $this->date('now +2 days', '19:15')),
      ],
    ]);
    $party = $this->createEvent([
      'title' => 'Tanzabend',
      'field_event_type' => 'party',
      'field_location' => $location->id(),
      'body' => [
        'value' => '<p>Tanzabschluss-Abend für alle.</p>',
        'format' => 'plain_text',
      ],
      'field_when' => [
        $this->dateDelta($this->date('now +3 days', '18:00'), $this->date('now +3 days', '22:00')),
      ],
    ]);
    $this->createEvent([
      'title' => 'Später Termin',
      'field_event_type' => 'party',
      'field_when' => [
        $this->dateDelta($this->date('now +40 days', '18:00'), $this->date('now +40 days', '20:00')),
      ],
    ]);

    $block = $this->blockManager->createInstance('salsa_events', [
      'types' => ['party', 'external_party'],
      'locations' => [],
      'limit' => 2,
      'show_ical' => TRUE,
    ]);
    $build = $block->build();

    self::assertArrayHasKey('events', $build);
    self::assertSame('3', $build['events']['#props']['columns']);
    $items = $build['events']['#slots']['items'];
    self::assertCount(2, $items);
    self::assertSame('Salsa im ballhaus goldfisch', $items[0]['#props']['title']);
    self::assertSame('ab 20:00 Uhr', $items[0]['#props']['time']);
    self::assertSame('Tanzabend', $items[1]['#props']['title']);
    self::assertSame('18:00 – 22:00 Uhr', $items[1]['#props']['time']);
    self::assertSame('Bootshaus Stralsund', $items[1]['#props']['location']);
    self::assertStringContainsString('Tanzabschluss-Abend', (string) $items[1]['#props']['text']);
    self::assertStringNotContainsString('plain_text', (string) $items[1]['#props']['text']);
    self::assertStringNotContainsString('content_format', (string) $items[1]['#props']['text']);
    self::assertSame($party->toUrl()->toString(), $items[1]['#props']['href']);

    // Calendar links carry the configured filter.
    $subscribe = $build['ical']['subscribe']['#props'];
    self::assertStringStartsWith('webcal://', $subscribe['href']);
    self::assertStringContainsString('/termine.ics', $subscribe['href']);
    self::assertStringContainsString('type=party%2Cexternal_party', $subscribe['href']);
    self::assertSame('/termine.ics?type=party%2Cexternal_party', $build['ical']['download']['#url']->toString());

    self::assertContains('node_list:event', $build['#cache']['tags']);
    self::assertContains('taxonomy_term_list:location', $build['#cache']['tags']);
    self::assertLessThanOrEqual(86400, $build['#cache']['max-age']);
  }

  /**
   * Tests filtering and the empty state of the upcoming events block.
   */
  public function testUpcomingEventsFiltering(): void {
    $location = $this->createLocation('Bootshaus Stralsund');
    $this->createEvent([
      'title' => 'Anfänger',
      'field_event_type' => 'course',
      'field_location' => $location->id(),
      'field_when' => [
        $this->dateDelta($this->date('now +2 days', '18:15'), $this->date('now +2 days', '19:15')),
      ],
    ]);

    $block = $this->blockManager->createInstance('salsa_events', [
      'types' => ['course'],
      'locations' => [],
      'limit' => 3,
      'show_ical' => FALSE,
    ]);
    $build = $block->build();
    self::assertCount(1, $build['events']['#slots']['items']);
    self::assertArrayNotHasKey('ical', $build);

    $block = $this->blockManager->createInstance('salsa_events', [
      'types' => ['party'],
      'locations' => [],
      'limit' => 3,
      'show_ical' => FALSE,
    ]);
    $build = $block->build();
    self::assertArrayNotHasKey('events', $build);
    self::assertArrayHasKey('empty', $build);
    self::assertSame('No upcoming events.', (string) $build['empty']['#markup']);

    // An unknown location filters everything out.
    $block = $this->blockManager->createInstance('salsa_events', [
      'types' => ['course'],
      'locations' => [9999],
      'limit' => 3,
      'show_ical' => FALSE,
    ]);
    self::assertArrayHasKey('empty', $block->build());
  }

  /**
   * Tests the course schedule block.
   */
  public function testCourseScheduleBlock(): void {
    $location = $this->createLocation(
      'Bootshaus Stralsund',
      'Friedrich-Naumann-Straße 5',
      'https://www.openstreetmap.org/search?query=Friedrich-Naumann-Stra%C3%9Fe%205',
    );
    $this->createEvent([
      'title' => 'Anfänger',
      'field_event_type' => 'course',
      'field_level' => 'Level 0',
      'field_location' => $location->id(),
      'field_when' => $this->recurringDeltas(
        $this->date('next monday', '18:15'),
        $this->date('next monday', '19:15'),
        'WEEKLY',
        'INTERVAL=1;BYDAY=MO',
        'COUNT=4',
      ),
    ]);
    $this->createEvent([
      'title' => 'Tanzfeeling u. mehr',
      'field_event_type' => 'course',
      'field_level' => 'Level 1',
      'field_location' => $location->id(),
      'field_when' => [
        $this->dateDelta($this->date('next tuesday', '19:30'), $this->date('next tuesday', '20:30')),
      ],
    ]);
    $this->createEvent([
      'title' => 'Zu weit in der Zukunft',
      'field_event_type' => 'course',
      'field_when' => [
        $this->dateDelta($this->date('now +200 days', '18:00'), $this->date('now +200 days', '19:00')),
      ],
    ]);

    $block = $this->blockManager->createInstance('salsa_course_schedule', [
      'locations' => [],
    ]);
    $build = $block->build();

    self::assertArrayHasKey('schedule', $build);
    $courses = $build['schedule']['#slots']['courses'];
    self::assertCount(2, $courses);
    self::assertSame('montag', $courses[0]['#props']['day']);
    self::assertSame('18:15 – 19:15 Uhr', $courses[0]['#props']['time']);
    self::assertSame('Anfänger', $courses[0]['#props']['title']);
    self::assertSame('Level 0', $courses[0]['#props']['level']);
    self::assertSame('Bootshaus Stralsund', $courses[0]['#props']['location']);
    self::assertSame('https://www.openstreetmap.org/search?query=Friedrich-Naumann-Stra%C3%9Fe%205', $courses[0]['#props']['location_href']);
    self::assertSame('dienstag', $courses[1]['#props']['day']);
    self::assertSame('Tanzfeeling u. mehr', $courses[1]['#props']['title']);
    self::assertStringNotContainsString('plain_text', json_encode($courses, JSON_THROW_ON_ERROR));
    self::assertStringNotContainsString('content_format', json_encode($courses, JSON_THROW_ON_ERROR));

    self::assertContains('node_list:event', $build['#cache']['tags']);
    self::assertContains('taxonomy_term_list:location', $build['#cache']['tags']);
  }

  /**
   * Tests that the summary is preferred over the body value.
   */
  public function testDescriptionPrefersSummary(): void {
    $this->createEvent([
      'title' => 'Tanzabend',
      'field_event_type' => 'party',
      'body' => [
        'value' => '<p>Der lange Beschreibungstext des Termins.</p>',
        'summary' => 'Kurzbeschreibung für die Karte.',
        'format' => 'plain_text',
      ],
      'field_when' => [
        $this->dateDelta($this->date('now +1 day', '18:00'), $this->date('now +1 day', '22:00')),
      ],
    ]);

    $block = $this->blockManager->createInstance('salsa_events', [
      'types' => ['party'],
      'locations' => [],
      'limit' => 3,
      'show_ical' => FALSE,
    ]);
    $text = (string) $block->build()['events']['#slots']['items'][0]['#props']['text'];

    self::assertStringContainsString('Kurzbeschreibung für die Karte.', $text);
    self::assertStringNotContainsString('Der lange Beschreibungstext', $text);
    self::assertStringNotContainsString('plain_text', $text);
  }

  /**
   * Tests the location filter of the course schedule block.
   */
  public function testCourseScheduleLocationFilter(): void {
    $location_a = $this->createLocation('Bootshaus Stralsund');
    $location_b = $this->createLocation('Treff ZuverSICht');
    foreach (['A' => $location_a->id(), 'B' => $location_b->id()] as $suffix => $tid) {
      $this->createEvent([
        'title' => 'Kurs ' . $suffix,
        'field_event_type' => 'course',
        'field_location' => $tid,
        'field_when' => [
          $this->dateDelta($this->date('next monday', '18:00'), $this->date('next monday', '19:00')),
        ],
      ]);
    }

    $block = $this->blockManager->createInstance('salsa_course_schedule', [
      'locations' => [(int) $location_b->id()],
    ]);
    $courses = $block->build()['schedule']['#slots']['courses'];
    self::assertCount(1, $courses);
    self::assertSame('Kurs B', $courses[0]['#props']['title']);
  }

}
