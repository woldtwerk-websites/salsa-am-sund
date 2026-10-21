<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Url;
use Drupal\workspace_events\Plugin\Block\EventCalendarBlock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the build data of the event calendar block.
 */
#[CoversClass(EventCalendarBlock::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class EventCalendarBlockTest extends EventKernelTestBase {

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
   * Tests the component props and the calendar subscription slot.
   */
  public function testBuild(): void {
    $location = $this->createLocation('Bootshaus Stralsund');
    $this->createCourse([
      'title' => 'Anfänger',
      'field_location' => $location->id(),
      'field_when' => [
        $this->dateDelta($this->date('now +2 days', '18:15'), $this->date('now +2 days', '19:15')),
      ],
    ]);

    $block = $this->blockManager->createInstance('salsa_event_calendar', []);
    $build = $block->build();

    self::assertArrayHasKey('calendar', $build);
    self::assertSame('salsa:calendar', $build['calendar']['#component']);

    $props = $build['calendar']['#props'];
    self::assertSame('/termine.json', Url::fromRoute('workspace_events.events_json')->toString());
    self::assertSame($props['events_url'], Url::fromRoute('workspace_events.events_json')->toString());
    self::assertSame($props['ical_url'], Url::fromRoute('workspace_events.ical')->toString());
    self::assertStringStartsWith('salsa-calendar', $props['id']);

    // Every organizer and location is offered as a filter.
    self::assertSame([
      ['value' => (string) $this->salsaAmSund, 'label' => 'Salsa am Sund'],
      ['value' => (string) $this->externalOrganizer, 'label' => 'Extern'],
    ], $props['organizers']);
    self::assertSame([
      ['value' => (string) $location->id(), 'label' => 'Bootshaus Stralsund'],
    ], $props['locations']);

    // The subscription offers the organizers checked and the course unchecked.
    $component = $build['calendar']['#slots']['subscribe'];
    self::assertSame('salsa:calendar-subscribe', $component['subscribe']['#component']);
    $options = $component['subscribe']['#props']['options'];
    self::assertSame(['organizers', 'organizers', 'courses'], array_column($options, 'group'));
    self::assertTrue($options[0]['checked']);
    self::assertTrue($options[1]['checked']);
    self::assertFalse($options[2]['checked']);
    self::assertSame([], $component['subscribe']['#props']['hidden']);

    // The block output does not depend on the request.
    self::assertSame([], $build['#cache']['contexts'] ?? []);
    self::assertSame(Cache::PERMANENT, $build['#cache']['max-age']);
    foreach ([
      'taxonomy_term_list:organizer',
      'taxonomy_term_list:location',
      'node_list:course',
      'taxonomy_term:' . $this->salsaAmSund,
      'taxonomy_term:' . $this->externalOrganizer,
      'taxonomy_term:' . $location->id(),
    ] as $tag) {
      self::assertContains($tag, $build['#cache']['tags']);
    }
  }

  /**
   * Tests that a second block gets its own element ID.
   */
  public function testUniqueId(): void {
    $first = $this->blockManager->createInstance('salsa_event_calendar', [])->build();
    $second = $this->blockManager->createInstance('salsa_event_calendar', [])->build();

    self::assertNotSame(
      $first['calendar']['#props']['id'],
      $second['calendar']['#props']['id'],
    );
  }

}
