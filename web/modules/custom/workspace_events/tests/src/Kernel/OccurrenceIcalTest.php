<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\Controller\IcalController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the iCal download of a single occurrence.
 */
#[CoversClass(IcalController::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class OccurrenceIcalTest extends EventKernelTestBase {

  /**
   * The iCal controller.
   */
  private IcalController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->controller = IcalController::create($this->container);
  }

  /**
   * Tests the download of one occurrence of a recurring event.
   */
  public function testOccurrenceDownload(): void {
    $start = $this->date('now +2 days', '20:00');
    $weekdays = [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU'];
    $deltas = $this->recurringDeltas(
      $start,
      $start->setTime(23, 0),
      'WEEKLY',
      'INTERVAL=1;BYDAY=' . $weekdays[(int) $start->format('N')],
      'COUNT=3',
      'event',
    );

    $location = $this->createLocation('Kulturhaus Stralsund');
    $event = $this->createEvent([
      'title' => 'Salsa-Nacht',
      'field_organizer' => $this->externalOrganizer,
      'field_location' => $location->id(),
      'field_when' => $deltas,
    ]);

    $response = $this->controller->occurrence(
      $this->request($event, 1),
      $event,
      1,
    );

    self::assertSame(200, $response->getStatusCode());
    $calendar = $this->readCalendar($response->getContent());
    $events = $calendar->select('VEVENT');
    self::assertCount(1, $events);

    $uid = 'event-' . $event->id() . '-1@salsa-am-sund.localhost';
    $ical_event = array_values($events)[0];
    self::assertSame($uid, $ical_event->UID?->getValue());
    self::assertSame('Salsa-Nacht', $ical_event->SUMMARY?->getValue());

    $expected_start = (new \DateTimeImmutable('@' . $deltas[1]['value']))
      ->setTimezone(new \DateTimeZone(self::TIMEZONE));
    self::assertSame($expected_start->format('Ymd\THis'), $ical_event->DTSTART?->getValue());
    self::assertSame(self::TIMEZONE, $ical_event->DTSTART['TZID']?->getValue());

    $disposition = $response->headers->get('Content-Disposition');
    \assert(is_string($disposition));
    self::assertStringStartsWith('attachment;', $disposition);
    self::assertStringContainsString('Salsa-Nacht-' . $start->modify('+1 week')->format('Y-m-d') . '.ics', $disposition);

    $cacheability = $response->getCacheableMetadata();
    self::assertContains('node:' . $event->id(), $cacheability->getCacheTags());
    self::assertContains('taxonomy_term:' . $location->id(), $cacheability->getCacheTags());
    self::assertContains('taxonomy_term:' . $this->externalOrganizer, $cacheability->getCacheTags());
  }

  /**
   * Tests that an unknown date delta is not found.
   */
  public function testUnknownDelta(): void {
    $event = $this->createEvent([
      'title' => 'Tanzabend',
      'field_when' => [
        $this->dateDelta($this->date('now +1 day', '20:00'), $this->date('now +1 day', '23:00')),
      ],
    ]);

    $this->expectException(NotFoundHttpException::class);
    $this->controller->occurrence($this->request($event, 1), $event, 1);
  }

  /**
   * Tests that other bundles have no occurrence download.
   */
  public function testOtherBundle(): void {
    NodeType::create([
      'type' => 'page',
      'name' => 'Seite',
    ])->save();
    $page = Node::create([
      'type' => 'page',
      'title' => 'Über uns',
      'status' => NodeInterface::PUBLISHED,
      'uid' => 1,
    ]);
    $page->save();

    $this->expectException(NotFoundHttpException::class);
    $this->controller->occurrence($this->request($page, 0), $page, 0);
  }

  /**
   * Builds the request of an occurrence download.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param int $delta
   *   The date delta.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   */
  private function request(NodeInterface $node, int $delta): Request {
    return Request::create(
      'https://salsa-am-sund.localhost/termine/' . $node->id() . '/' . $delta . '/ical',
    );
  }

  /**
   * Parses an iCal response.
   *
   * @param string|false $content
   *   The response content.
   *
   * @return \Sabre\VObject\Component\VCalendar
   *   The parsed calendar.
   */
  private function readCalendar(string|false $content): VCalendar {
    self::assertIsString($content);
    $calendar = Reader::read($content);
    self::assertInstanceOf(VCalendar::class, $calendar);
    return $calendar;
  }

}
