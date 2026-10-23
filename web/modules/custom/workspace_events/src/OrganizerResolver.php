<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Resolves the fixed organizer terms by UUID.
 *
 * The terms are content, not configuration, so they are created by the install
 * and update hooks. Their UUIDs are fixed, so every environment and the
 * conversion script can refer to the same term without looking it up by name,
 * which editors may change.
 *
 * The lookup is cached per request, so parsing a filter and rendering a block
 * does not query the terms again.
 */
final class OrganizerResolver implements OrganizerLookupInterface {

  /**
   * The machine name of the organizer vocabulary.
   */
  public const string VOCABULARY = 'organizer';

  /**
   * The UUID of the "Salsa am Sund" term.
   */
  public const string SALSA_AM_SUND_UUID = 'ff101b5e-48d9-4dd4-9711-b69057fa25c2';

  /**
   * The UUID of the "Sonstige" term.
   */
  public const string EXTERNAL_UUID = '5b0a52b3-9b74-4946-99c5-3c8a08919ac0';

  /**
   * The terms to create, keyed by UUID, in display order.
   *
   * @var array<string, array{name: string, weight: int}>
   */
  public const array TERMS = [
    self::SALSA_AM_SUND_UUID => [
      'name' => 'Salsa am Sund',
      'weight' => 0,
    ],
    self::EXTERNAL_UUID => [
      'name' => 'Sonstige',
      'weight' => 1,
    ],
  ];

  /**
   * Maps the legacy event type keys to the organizer term UUIDs.
   *
   * @var array<string, string>
   */
  public const array LEGACY_TYPE_UUIDS = [
    'party' => self::SALSA_AM_SUND_UUID,
    'external_party' => self::EXTERNAL_UUID,
  ];

  /**
   * The term IDs, keyed by UUID, or NULL when they have not been loaded yet.
   *
   * @var array<string, int>|null
   */
  private ?array $ids = NULL;

  /**
   * Constructs an OrganizerResolver.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns the term ID of a fixed organizer term.
   *
   * @param string $uuid
   *   The term UUID, one of the constants of this class.
   *
   * @return int|null
   *   The term ID, or NULL when the term does not exist.
   */
  public function id(string $uuid): ?int {
    return $this->ids()[$uuid] ?? NULL;
  }

  /**
   * Returns the term IDs keyed by UUID.
   *
   * @return array<string, int>
   *   The term IDs of the existing fixed terms.
   */
  public function ids(): array {
    if ($this->ids === NULL) {
      $uuids = array_keys(self::TERMS);
      $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
      // The feed and the blocks are anonymous, so the lookup must not depend
      // on term access.
      $tids = $term_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('vid', self::VOCABULARY)
        ->condition('uuid', $uuids, 'IN')
        ->execute();

      $this->ids = [];
      foreach ($term_storage->loadMultiple($tids) as $term) {
        \assert($term instanceof TermInterface);
        $this->ids[(string) $term->uuid()] = (int) $term->id();
      }
    }
    return $this->ids;
  }

}
