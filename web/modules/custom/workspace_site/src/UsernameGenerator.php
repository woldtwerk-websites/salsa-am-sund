<?php

declare(strict_types=1);

namespace Drupal\workspace_site;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;

/**
 * Generates unique user names from the first and last name.
 */
final class UsernameGenerator {

  /**
   * Characters reserved for a numeric suffix such as " 10000".
   */
  private const SUFFIX_RESERVE = 5;

  /**
   * Constructs the user name generator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection, used to escape LIKE wildcards.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
  ) {}

  /**
   * Generates a unique user name from a first and last name.
   *
   * @param string $firstName
   *   The first name.
   * @param string $lastName
   *   The last name.
   * @param int|null $excludeUid
   *   A user ID to ignore when checking for collisions, for example the user
   *   being renamed.
   *
   * @return string
   *   The generated user name.
   */
  public function generate(string $firstName, string $lastName, ?int $excludeUid = NULL): string {
    $base = $this->sanitize($firstName, $lastName);
    if ($base === '') {
      return $this->generateRandom();
    }

    $query = $this->database->select('users_field_data', 'u')
      ->fields('u', ['name'])
      ->condition('u.name', $this->database->escapeLike($base) . '%', 'LIKE');
    if ($excludeUid !== NULL) {
      $query->condition('u.uid', $excludeUid, '<>');
    }

    $statement = $query->execute();
    $names = $statement === NULL ? [] : $statement->fetchCol();
    $existing = [];
    foreach ($names as $name) {
      if (is_string($name)) {
        $existing[mb_strtolower($name)] = TRUE;
      }
    }

    $candidate = $base;
    $suffix = 2;
    while (isset($existing[mb_strtolower($candidate)])) {
      $candidate = $this->withSuffix($base, $suffix);
      $suffix++;
    }

    return $candidate;
  }

  /**
   * Builds a user name with a numeric suffix.
   *
   * @param string $base
   *   The sanitized base name.
   * @param int $suffix
   *   The suffix number.
   *
   * @return string
   *   The name, truncated so that the suffix fits the maximum length.
   */
  private function withSuffix(string $base, int $suffix): string {
    $suffix_text = ' ' . $suffix;
    $length = UserInterface::USERNAME_MAX_LENGTH - mb_strlen($suffix_text);

    return mb_substr($base, 0, $length) . $suffix_text;
  }

  /**
   * Sanitizes a first and last name into a valid user name base.
   *
   * The allowed characters and whitespace rules match core's
   * UserNameConstraintValidator.
   *
   * @param string $firstName
   *   The first name.
   * @param string $lastName
   *   The last name.
   *
   * @return string
   *   The sanitized base name, truncated to leave room for a suffix.
   */
  private function sanitize(string $firstName, string $lastName): string {
    $name = trim($firstName) . ' ' . trim($lastName);
    // Core's first user name check is byte-wise and allows every multibyte
    // character, so remove only the disallowed ASCII characters here.
    $name = preg_replace('/[^\x{80}-\x{F7} a-z0-9@+_.\'\-]/i', '', $name) ?? '';
    // Core's second check rejects these non-printable and special code points.
    $name = preg_replace(
      '/[\x{80}-\x{A0}'
      . '\x{AD}'
      . '\x{2000}-\x{200F}'
      . '\x{2028}-\x{202F}'
      . '\x{205F}-\x{206F}'
      . '\x{FEFF}'
      . '\x{FF01}-\x{FF60}'
      . '\x{FFF9}-\x{FFFD}'
      . '\x{0}-\x{1F}]/u',
      '',
      $name,
    ) ?? '';
    $name = preg_replace('/\s+/u', ' ', $name) ?? '';
    $name = trim($name);
    if ($name === '') {
      return '';
    }

    $name = mb_substr($name, 0, UserInterface::USERNAME_MAX_LENGTH - self::SUFFIX_RESERVE);

    return rtrim($name);
  }

  /**
   * Generates a random fallback user name.
   *
   * @return string
   *   A user name in the form "user-" followed by eight hex characters.
   */
  private function generateRandom(): string {
    $storage = $this->entityTypeManager->getStorage('user');
    do {
      $name = 'user-' . bin2hex(random_bytes(4));
    } while ($this->exists($storage, $name));

    return $name;
  }

  /**
   * Checks whether a user name already exists.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The user storage.
   * @param string $name
   *   The user name.
   *
   * @return bool
   *   TRUE when a user with that name exists.
   */
  private function exists(EntityStorageInterface $storage, string $name): bool {
    $count = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('name', $name)
      ->count()
      ->range(0, 1)
      ->execute();

    return (bool) $count;
  }

}
