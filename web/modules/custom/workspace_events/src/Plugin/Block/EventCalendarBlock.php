<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Provides a calendar of events and courses.
 *
 * The calendar itself is a FullCalendar web component that loads its
 * occurrences from the JSON endpoint. Filtering and navigation happen in the
 * browser, so the block output does not depend on the request.
 */
#[Block(
  id: 'salsa_event_calendar',
  admin_label: new TranslatableMarkup('Event calendar'),
  category: new TranslatableMarkup('Salsa am Sund'),
)]
final class EventCalendarBlock extends EventBlockBase {

  /**
   * {@inheritdoc}
   *
   * @return array<mixed>
   *   The render array of the block.
   */
  public function build(): array {
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheTags([
      'taxonomy_term_list:organizer',
      'taxonomy_term_list:location',
      'node_list:course',
    ]);

    $organizers = [];
    foreach ($this->getOrganizerTerms() as $tid => $term) {
      $organizers[] = [
        'value' => (string) $tid,
        'label' => (string) $term->label(),
      ];
      $cacheability->addCacheableDependency($term);
    }

    $locations = [];
    foreach ($this->getLocationTerms() as $tid => $term) {
      $locations[] = [
        'value' => (string) $tid,
        'label' => (string) $term->label(),
      ];
      $cacheability->addCacheableDependency($term);
    }

    /** @var array<string, mixed> $build */
    $build = [
      'calendar' => [
        '#type' => 'component',
        '#component' => 'salsa:calendar',
        '#props' => [
          'id' => Html::getUniqueId('salsa-calendar'),
          'events_url' => Url::fromRoute('workspace_events.events_json')->toString(),
          'ical_url' => Url::fromRoute('workspace_events.ical')->toString(),
          'organizers' => $organizers,
          'locations' => $locations,
        ],
        '#slots' => [
          // Every organizer is checked, the courses are offered unchecked, like
          // the upcoming events block.
          'subscribe' => $this->buildIcalLinks([], [], $this->findCourses(), TRUE, FALSE),
        ],
      ],
    ];

    // The filters list every organizer and location, so their labels are part
    // of the output. The occurrence data is fetched by the browser.
    $cacheability->applyTo($build);

    return $build;
  }

}
