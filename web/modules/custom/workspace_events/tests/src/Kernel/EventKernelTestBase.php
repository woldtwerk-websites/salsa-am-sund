<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\smart_date_recur\Entity\SmartDateRule;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Base class for workspace_events kernel tests.
 *
 * Builds the event content model programmatically, so the tests only cover
 * this module's logic and not the site's configuration.
 */
abstract class EventKernelTestBase extends CanvasKernelTestBase {

  use UserCreationTrait;

  /**
   * The timezone used by the test date values.
   */
  protected const string TIMEZONE = 'Europe/Berlin';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_sdc',
    'field',
    'node',
    'taxonomy',
    'content_moderation',
    'workflows',
    'pathauto',
    'token',
    'smart_date',
    'smart_date_recur',
    'workspace_events',
  ];

  /**
   * The taxonomy term storage.
   */
  protected EntityStorageInterface $termStorage;

  /**
   * The node storage.
   */
  protected EntityStorageInterface $nodeStorage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('smart_date_rule');
    $this->installEntitySchema('smart_date_override');
    $this->installConfig(['node', 'taxonomy', 'filter', 'smart_date', 'smart_date_recur']);

    $this->createContentModel();
    $this->setUpCurrentUser([], ['access content']);
    $this->termStorage = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $this->nodeStorage = $this->container->get('entity_type.manager')->getStorage('node');
  }

  /**
   * Creates the event content model.
   */
  protected function createContentModel(): void {
    Vocabulary::create([
      'vid' => 'location',
      'name' => 'Orte',
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'taxonomy_term',
      'type' => 'string_long',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'taxonomy_term',
      'bundle' => 'location',
      'label' => 'Adresse',
      'field_storage' => FieldStorageConfig::loadByName('taxonomy_term', 'field_address'),
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_map_link',
      'entity_type' => 'taxonomy_term',
      'type' => 'link',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_map_link',
      'entity_type' => 'taxonomy_term',
      'bundle' => 'location',
      'label' => 'Kartenlink',
      'field_storage' => FieldStorageConfig::loadByName('taxonomy_term', 'field_map_link'),
      'settings' => ['title' => 1, 'link_type' => 17],
    ])->save();

    NodeType::create([
      'type' => 'event',
      'name' => 'Termin',
      'new_revision' => TRUE,
      'display_submitted' => FALSE,
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_when',
      'entity_type' => 'node',
      'type' => 'smartdate',
      'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
    ])->save();
    $when = FieldConfig::create([
      'field_name' => 'field_when',
      'entity_type' => 'node',
      'bundle' => 'event',
      'label' => 'Datum & Uhrzeit',
      'required' => TRUE,
      'field_storage' => FieldStorageConfig::loadByName('node', 'field_when'),
    ]);
    $when->setThirdPartySetting('smart_date_recur', 'allow_recurring', TRUE);
    $when->setThirdPartySetting('smart_date_recur', 'month_limit', 12);
    $when->save();

    FieldStorageConfig::create([
      'field_name' => 'field_event_type',
      'entity_type' => 'node',
      'type' => 'list_string',
      'cardinality' => 1,
      'settings' => [
        'allowed_values' => [
          'course' => 'Kurs',
          'party' => 'Party',
          'external_party' => 'Externe Party',
        ],
        'allowed_values_function' => '',
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_event_type',
      'entity_type' => 'node',
      'bundle' => 'event',
      'label' => 'Art',
      'required' => TRUE,
      'field_storage' => FieldStorageConfig::loadByName('node', 'field_event_type'),
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_location',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => 1,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_location',
      'entity_type' => 'node',
      'bundle' => 'event',
      'label' => 'Ort',
      'field_storage' => FieldStorageConfig::loadByName('node', 'field_location'),
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => [
          'target_bundles' => ['location' => 'location'],
          'sort' => ['field' => 'name', 'direction' => 'ASC'],
          'auto_create' => FALSE,
        ],
      ],
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_level',
      'entity_type' => 'node',
      'type' => 'string',
      'cardinality' => 1,
      'settings' => ['max_length' => 255, 'is_ascii' => FALSE, 'case_sensitive' => FALSE],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_level',
      'entity_type' => 'node',
      'bundle' => 'event',
      'label' => 'Level',
      'field_storage' => FieldStorageConfig::loadByName('node', 'field_level'),
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_link',
      'entity_type' => 'node',
      'type' => 'link',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_link',
      'entity_type' => 'node',
      'bundle' => 'event',
      'label' => 'Weitere Infos (Link)',
      'field_storage' => FieldStorageConfig::loadByName('node', 'field_link'),
      'settings' => ['title' => 1, 'link_type' => 17],
    ])->save();

    if (FieldStorageConfig::loadByName('node', 'body') === NULL) {
      FieldStorageConfig::create([
        'field_name' => 'body',
        'entity_type' => 'node',
        'type' => 'text_with_summary',
        'cardinality' => 1,
      ])->save();
    }
    FieldConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => 'event',
      'label' => 'Beschreibung',
      'field_storage' => FieldStorageConfig::loadByName('node', 'body'),
      'settings' => ['display_summary' => TRUE, 'allowed_formats' => []],
    ])->save();
  }

  /**
   * Creates a location term.
   *
   * @param string $name
   *   The term name.
   * @param string $address
   *   The address.
   * @param string $mapLink
   *   The map link.
   *
   * @return \Drupal\taxonomy\TermInterface
   *   The term.
   */
  protected function createLocation(string $name, string $address = '', string $mapLink = ''): TermInterface {
    $term = Term::create([
      'vid' => 'location',
      'name' => $name,
      'field_address' => $address,
      'field_map_link' => $mapLink === '' ? NULL : ['uri' => $mapLink, 'title' => 'Karte'],
    ]);
    $term->save();
    return $term;
  }

  /**
   * Creates a date value in the test timezone.
   *
   * @param string $modifier
   *   A date string, optionally relative to now.
   * @param string $time
   *   The time of day.
   *
   * @return \DateTimeImmutable
   *   The date.
   */
  protected function date(string $modifier, string $time = '00:00'): \DateTimeImmutable {
    return new \DateTimeImmutable($modifier . ' ' . $time, new \DateTimeZone(self::TIMEZONE));
  }

  /**
   * Creates a single date delta for a smart date field.
   *
   * @param \DateTimeImmutable $start
   *   The start.
   * @param \DateTimeImmutable|null $end
   *   The end, or NULL for an event without a known end.
   *
   * @return array<string, mixed>
   *   The field delta.
   */
  protected function dateDelta(\DateTimeImmutable $start, ?\DateTimeImmutable $end): array {
    $end ??= $start;
    return [
      'value' => $start->getTimestamp(),
      'end_value' => $end->getTimestamp(),
      'duration' => (int) (($end->getTimestamp() - $start->getTimestamp()) / 60),
      'timezone' => self::TIMEZONE,
    ];
  }

  /**
   * Creates an all-day date delta.
   *
   * @param \DateTimeImmutable $day
   *   The day.
   *
   * @return array<string, mixed>
   *   The field delta.
   */
  protected function allDayDelta(\DateTimeImmutable $day): array {
    $start = $day->setTime(0, 0);
    $end = $day->setTime(23, 59);
    return [
      'value' => $start->getTimestamp(),
      'end_value' => $end->getTimestamp(),
      'duration' => 1439,
      'timezone' => self::TIMEZONE,
    ];
  }

  /**
   * Creates a recurrence rule and returns its materialized date deltas.
   *
   * @param \DateTimeImmutable $start
   *   The first occurrence start.
   * @param \DateTimeImmutable $end
   *   The first occurrence end.
   * @param string $frequency
   *   The recurrence frequency.
   * @param string $parameters
   *   The recurrence parameters.
   * @param string $limit
   *   The recurrence limit, for example COUNT=4. Empty for unlimited.
   *
   * @return array<int, array<string, mixed>>
   *   The date deltas.
   */
  protected function recurringDeltas(\DateTimeImmutable $start, \DateTimeImmutable $end, string $frequency, string $parameters, string $limit = ''): array {
    $rule = SmartDateRule::create([
      'freq' => $frequency,
      'limit' => $limit,
      'unlimited' => $limit === '',
      'entity_type' => 'node',
      'bundle' => 'event',
      'field_name' => 'field_when',
      'start' => $start->getTimestamp(),
      'end' => $end->getTimestamp(),
      'parameters' => $parameters,
    ]);
    $rule->setTimezone(self::TIMEZONE);
    $instances = $rule->getRuleInstances(strtotime('+12 months'));
    self::assertIsArray($instances);
    $rule->set('instances', ['data' => $instances]);
    $rule->save();

    $deltas = [];
    foreach ($rule->getStoredInstances() as $index => $instance) {
      $deltas[] = [
        'value' => $instance['value'],
        'end_value' => $instance['end_value'],
        'duration' => (int) (($instance['end_value'] - $instance['value']) / 60),
        'timezone' => self::TIMEZONE,
        'rrule' => $rule->id(),
        'rrule_index' => $index,
      ];
    }
    return $deltas;
  }

  /**
   * Creates an event node.
   *
   * @param array<string, mixed> $values
   *   The node values.
   *
   * @return \Drupal\node\NodeInterface
   *   The event.
   */
  protected function createEvent(array $values): NodeInterface {
    $values += [
      'type' => 'event',
      'status' => NodeInterface::PUBLISHED,
      'uid' => 1,
    ];
    $node = Node::create($values);
    $node->save();
    return $node;
  }

}
