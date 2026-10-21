<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\workspace_events\EventFilter;
use Drupal\workspace_events\EventOccurrence;

/**
 * Provides a block with the weekly course schedule.
 */
#[Block(
  id: 'salsa_course_schedule',
  admin_label: new TranslatableMarkup('Course schedule'),
  category: new TranslatableMarkup('Salsa am Sund'),
)]
final class CourseScheduleBlock extends EventBlockBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default configuration.
   */
  public function defaultConfiguration(): array {
    /** @var array<string, mixed> $defaults */
    $defaults = parent::defaultConfiguration();
    return [
      'locations' => [],
    ] + $defaults;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The block configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The block configuration form.
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['locations'] = [
      '#type' => 'select',
      '#title' => $this->t('Locations'),
      '#description' => $this->t('Leave empty to show all locations.'),
      '#options' => $this->getLocationOptions(),
      '#multiple' => TRUE,
      '#default_value' => $this->getConfiguredLocations(),
      '#empty_option' => $this->t('- All locations -'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The block configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $locations = $form_state->getValue('locations');
    $ids = [];
    if (is_array($locations)) {
      foreach ($locations as $location) {
        if (is_scalar($location) && is_numeric($location)) {
          $ids[] = (int) $location;
        }
      }
    }
    $this->configuration['locations'] = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
  }

  /**
   * {@inheritdoc}
   *
   * @return array<mixed>
   *   The render array of the block.
   */
  public function build(): array {
    $now = new \DateTimeImmutable('now');
    $occurrences = $this->repository->findOccurrences(
      new EventFilter(['course'], $this->getConfiguredLocations()),
      $now,
      $now->modify('+120 days'),
    );

    // Show one entry per course, based on its next occurrence.
    $by_node = [];
    foreach ($occurrences as $occurrence) {
      $nid = (int) $occurrence->node->id();
      $by_node[$nid] ??= $occurrence;
    }
    $courses = array_values($by_node);
    usort($courses, static function (EventOccurrence $a, EventOccurrence $b): int {
      return [
        (int) $a->start->format('N'),
        $a->start->format('H:i'),
        (int) $a->node->id(),
      ] <=> [
        (int) $b->start->format('N'),
        $b->start->format('H:i'),
        (int) $b->node->id(),
      ];
    });

    /** @var array<string, mixed> $build */
    $build = [];
    if ($courses !== []) {
      $items = [];
      foreach ($courses as $occurrence) {
        $node = $occurrence->node;
        $props = [
          'day' => self::WEEKDAYS[(int) $occurrence->start->format('N')],
          'time' => $this->formatTime($occurrence) ?? '',
          'title' => (string) $node->label(),
        ];
        $level = $node->get('field_level')->getString();
        if ($level !== '') {
          $props['level'] = $level;
        }
        if ($occurrence->location !== NULL) {
          $props['location'] = (string) $occurrence->location->label();
          if ($occurrence->location->hasField('field_map_link') && !$occurrence->location->get('field_map_link')->isEmpty()) {
            $map_link = $occurrence->location->get('field_map_link')->first()?->get('uri')->getString() ?? '';
            if ($map_link !== '') {
              $props['location_href'] = $map_link;
            }
          }
        }
        $items[] = [
          '#type' => 'component',
          '#component' => 'salsa:course',
          '#props' => $props,
        ];
      }

      $build['schedule'] = [
        '#type' => 'component',
        '#component' => 'salsa:schedule',
        '#slots' => [
          'courses' => $items,
        ],
      ];
    }

    $this->applyOccurrenceCache($build, $courses);

    return $build;
  }

  /**
   * Returns the configured location term IDs.
   *
   * @return int[]
   *   The location term IDs.
   */
  private function getConfiguredLocations(): array {
    return $this->getConfiguredIds('locations');
  }

}
