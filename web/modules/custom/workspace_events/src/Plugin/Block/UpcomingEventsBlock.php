<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\workspace_events\EventFilter;

/**
 * Provides a block with the upcoming events.
 */
#[Block(
  id: 'salsa_events',
  admin_label: new TranslatableMarkup('Upcoming events'),
  category: new TranslatableMarkup('Salsa am Sund'),
)]
final class UpcomingEventsBlock extends EventBlockBase {

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
      'types' => ['party', 'external_party'],
      'locations' => [],
      'limit' => 3,
      'show_ical' => TRUE,
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
    $form['types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Event types'),
      '#options' => $this->getTypeOptions(),
      '#default_value' => $this->getConfiguredTypes(),
      '#required' => TRUE,
    ];

    $form['locations'] = [
      '#type' => 'select',
      '#title' => $this->t('Locations'),
      '#description' => $this->t('Leave empty to show all locations.'),
      '#options' => $this->getLocationOptions(),
      '#multiple' => TRUE,
      '#default_value' => $this->getConfiguredLocations(),
      '#empty_option' => $this->t('- All locations -'),
    ];

    $form['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of events'),
      '#min' => 1,
      '#max' => 50,
      '#default_value' => $this->getConfiguredLimit(),
      '#required' => TRUE,
    ];

    $form['show_ical'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show calendar subscription links'),
      '#default_value' => $this->getConfiguredShowIcal(),
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
    $types = $form_state->getValue('types');
    $selected = is_array($types) ? array_keys(array_filter($types)) : [];
    $valid_types = [];
    foreach ($selected as $type) {
      if (is_string($type) && in_array($type, EventFilter::TYPES, TRUE)) {
        $valid_types[] = $type;
      }
    }
    $this->configuration['types'] = $valid_types;

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

    $limit = $form_state->getValue('limit');
    $this->configuration['limit'] = is_scalar($limit) ? max(1, min(50, (int) $limit)) : 3;
    $this->configuration['show_ical'] = (bool) $form_state->getValue('show_ical');
  }

  /**
   * {@inheritdoc}
   *
   * @return array<mixed>
   *   The render array of the block.
   */
  public function build(): array {
    $types = $this->getConfiguredTypes();
    $locations = $this->getConfiguredLocations();
    $limit = $this->getConfiguredLimit();

    $now = new \DateTimeImmutable('now');
    $occurrences = $this->repository->findOccurrences(
      new EventFilter($types, $locations),
      $now,
      $now->modify('+365 days'),
      $limit,
    );

    /** @var array<string, mixed> $build */
    $build = [];
    if ($occurrences === []) {
      $build['empty'] = [
        '#markup' => $this->t('No upcoming events.'),
      ];
    }
    else {
      $items = [];
      foreach ($occurrences as $occurrence) {
        $node = $occurrence->node;
        $props = [
          'date' => $occurrence->start->format('Y-m-d'),
          'title' => (string) $node->label(),
          'href' => $this->getHref($node),
        ];
        $time = $this->formatTime($occurrence);
        if ($time !== NULL) {
          $props['time'] = $time;
        }
        if ($occurrence->location !== NULL) {
          $props['location'] = (string) $occurrence->location->label();
        }
        $description = $this->getDescription($node);
        if ($description !== NULL) {
          $props['text'] = $description;
        }
        $items[] = [
          '#type' => 'component',
          '#component' => 'salsa:event',
          '#props' => $props,
        ];
      }

      $build['events'] = [
        '#type' => 'component',
        '#component' => 'salsa:grid',
        '#props' => [
          'columns' => '3',
          'gap' => 'md',
        ],
        '#slots' => [
          'items' => $items,
        ],
      ];
    }

    if ($this->getConfiguredShowIcal()) {
      $build['ical'] = $this->buildIcalLinks($types, $locations);
    }

    $this->applyOccurrenceCache($build, $occurrences);

    return $build;
  }

  /**
   * Builds the calendar subscription and download links.
   *
   * @param string[] $types
   *   The configured event types.
   * @param int[] $locations
   *   The configured location term IDs.
   *
   * @return array<string, mixed>
   *   The render array of the links.
   */
  private function buildIcalLinks(array $types, array $locations): array {
    $absolute = $this->getCalendarUrl($types, $locations, TRUE)->toString();
    $webcal = preg_replace('#^https?://#', 'webcal://', $absolute) ?? $absolute;

    $build['subscribe'] = [
      '#type' => 'component',
      '#component' => 'salsa:button',
      '#props' => [
        'label' => (string) $this->t('Subscribe to calendar'),
        'href' => $webcal,
        'variant' => 'outline',
        'size' => 'md',
        'icon' => 'external-link',
      ],
    ];
    $build['download'] = [
      '#type' => 'link',
      '#title' => $this->t('Download .ics'),
      '#url' => $this->getCalendarUrl($types, $locations),
    ];

    return $build;
  }

  /**
   * Returns the configured event types.
   *
   * @return string[]
   *   The event type keys.
   */
  private function getConfiguredTypes(): array {
    $values = $this->getConfiguration()['types'] ?? [];
    if (!is_array($values)) {
      return [];
    }
    $types = [];
    foreach ($values as $value) {
      if (is_string($value) && in_array($value, EventFilter::TYPES, TRUE) && !in_array($value, $types, TRUE)) {
        $types[] = $value;
      }
    }
    return $types;
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

  /**
   * Returns the configured number of events.
   */
  private function getConfiguredLimit(): int {
    $limit = $this->getConfiguration()['limit'] ?? 3;
    return is_scalar($limit) ? max(1, min(50, (int) $limit)) : 3;
  }

  /**
   * Returns whether the calendar links are enabled.
   */
  private function getConfiguredShowIcal(): bool {
    return (bool) ($this->getConfiguration()['show_ical'] ?? TRUE);
  }

}
