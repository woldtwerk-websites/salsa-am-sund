<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\Block;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\EventDescriptionFormatter;
use Drupal\workspace_events\EventOccurrence;
use Drupal\workspace_events\EventOccurrenceRepository;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for blocks that render event occurrences.
 */
abstract class EventBlockBase extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * German weekday keys, indexed by ISO-8601 day number.
   */
  protected const array WEEKDAYS = [
    1 => 'montag',
    2 => 'dienstag',
    3 => 'mittwoch',
    4 => 'donnerstag',
    5 => 'freitag',
    6 => 'samstag',
    7 => 'sonntag',
  ];

  /**
   * Constructs an EventBlockBase.
   *
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param array<string, mixed> $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\workspace_events\EventOccurrenceRepository $repository
   *   The occurrence repository.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\workspace_events\EventDescriptionFormatter $descriptionFormatter
   *   The event description formatter.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    protected readonly EventOccurrenceRepository $repository,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EventDescriptionFormatter $descriptionFormatter,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param array<string, mixed> $plugin_definition
   *   The plugin implementation definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $repository = $container->get(EventOccurrenceRepository::class);
    \assert($repository instanceof EventOccurrenceRepository);
    $entity_type_manager = $container->get(EntityTypeManagerInterface::class);
    \assert($entity_type_manager instanceof EntityTypeManagerInterface);
    $description_formatter = $container->get(EventDescriptionFormatter::class);
    \assert($description_formatter instanceof EventDescriptionFormatter);

    // @phpstan-ignore-next-line
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $repository,
      $entity_type_manager,
      $description_formatter,
    );
  }

  /**
   * Returns the event type options for the block form.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The options, keyed by type.
   */
  protected function getTypeOptions(): array {
    return [
      'course' => $this->t('Course'),
      'party' => $this->t('Party'),
      'external_party' => $this->t('External party'),
    ];
  }

  /**
   * Returns the location options for the block form.
   *
   * @return array<int, string>
   *   The term labels, keyed by term ID.
   */
  protected function getLocationOptions(): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $storage->loadByProperties(['vid' => 'location']);
    uasort($terms, static fn (TermInterface $a, TermInterface $b): int => strcasecmp((string) $a->label(), (string) $b->label()));
    $options = [];
    foreach ($terms as $term) {
      \assert($term instanceof TermInterface);
      $options[(int) $term->id()] = (string) $term->label();
    }
    return $options;
  }

  /**
   * Returns a list of configured taxonomy term IDs.
   *
   * @param string $key
   *   The configuration key.
   *
   * @return int[]
   *   The positive term IDs.
   */
  protected function getConfiguredIds(string $key): array {
    $values = $this->getConfiguration()[$key] ?? [];
    if (!is_array($values)) {
      return [];
    }
    $ids = [];
    foreach ($values as $value) {
      if (!is_int($value) && !is_string($value)) {
        continue;
      }
      if (is_string($value) && !ctype_digit($value)) {
        continue;
      }
      $id = (int) $value;
      if ($id > 0 && !in_array($id, $ids, TRUE)) {
        $ids[] = $id;
      }
    }
    return $ids;
  }

  /**
   * Formats the time of an occurrence for display.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   *
   * @return string|null
   *   The formatted time, or NULL for all-day events.
   */
  protected function formatTime(EventOccurrence $occurrence): ?string {
    if ($occurrence->allDay) {
      return NULL;
    }
    $start = $occurrence->start->format('H:i');
    if ($occurrence->end === NULL) {
      return (string) $this->t('ab @start Uhr', ['@start' => $start]);
    }
    return (string) $this->t('@start – @end Uhr', [
      '@start' => $start,
      '@end' => $occurrence->end->format('H:i'),
    ]);
  }

  /**
   * Returns the link target of an event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return string
   *   The external link when set, otherwise the node path.
   */
  protected function getHref(NodeInterface $node): string {
    if ($node->hasField('field_link') && !$node->get('field_link')->isEmpty()) {
      $uri = $node->get('field_link')->first()?->get('uri')->getString() ?? '';
      if ($uri !== '') {
        return $uri;
      }
    }
    return $node->toUrl()->toString();
  }

  /**
   * Returns the description of an event as safe HTML.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Drupal\Component\Render\MarkupInterface|null
   *   The description, or NULL when the event has no body.
   */
  protected function getDescription(NodeInterface $node): ?MarkupInterface {
    return $this->descriptionFormatter->toHtml($node);
  }

  /**
   * Returns the calendar route for the given filter.
   *
   * @param string[] $types
   *   The event type keys.
   * @param int[] $locations
   *   The location term IDs.
   * @param bool $absolute
   *   Whether to generate an absolute URL.
   *
   * @return \Drupal\Core\Url
   *   The URL object.
   */
  protected function getCalendarUrl(array $types, array $locations, bool $absolute = FALSE): Url {
    $query = [];
    if ($types !== []) {
      $query['type'] = implode(',', $types);
    }
    if ($locations !== []) {
      $query['location'] = implode(',', $locations);
    }
    return Url::fromRoute('workspace_events.ical', [], [
      'query' => $query,
      'absolute' => $absolute,
    ]);
  }

  /**
   * Applies cache metadata for a list of occurrences to a render array.
   *
   * @param array<mixed> $build
   *   The render array.
   * @param \Drupal\workspace_events\EventOccurrence[] $occurrences
   *   The rendered occurrences.
   */
  protected function applyOccurrenceCache(array &$build, array $occurrences): void {
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheTags([
      'node_list:event',
      'taxonomy_term_list:location',
    ]);

    $now = time();
    $max_age = 86400;
    foreach ($occurrences as $occurrence) {
      $cacheability->addCacheableDependency($occurrence->node);
      if ($occurrence->location !== NULL) {
        $cacheability->addCacheableDependency($occurrence->location);
      }
      // The description is rendered through the body's text format.
      if ($occurrence->node->hasField('body') && !$occurrence->node->get('body')->isEmpty()) {
        $format = $occurrence->node->get('body')->first()?->get('format')->getString() ?? '';
        if ($format !== '') {
          $cacheability->addCacheTags(['config:filter.format.' . $format]);
        }
      }
      $end = $occurrence->end !== NULL
        ? $occurrence->endTimestamp()
        : $occurrence->start->setTime(23, 59)->getTimestamp();
      $max_age = min($max_age, max(0, $end - $now));
    }
    $cacheability->setCacheMaxAge($max_age);
    $cacheability->applyTo($build);
  }

}
