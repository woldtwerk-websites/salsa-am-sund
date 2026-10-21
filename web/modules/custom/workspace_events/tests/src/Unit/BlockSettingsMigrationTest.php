<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Unit;

use Drupal\workspace_events\BlockSettingsMigration;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the conversion of legacy block settings.
 */
#[CoversClass(BlockSettingsMigration::class)]
#[Group('workspace_events')]
final class BlockSettingsMigrationTest extends UnitTestCase {

  /**
   * Provides stored type keys and the expected block settings.
   *
   * @return iterable<string, array{0: array<mixed>, 1: int[], 2: bool}>
   *   The test cases: type keys, organizer IDs and the courses flag.
   */
  public static function typesProvider(): iterable {
    yield 'parties' => [['party', 'external_party'], [11, 12], FALSE];
    yield 'single party' => [['party'], [11], FALSE];
    yield 'external parties' => [['external_party'], [12], FALSE];
    yield 'courses' => [['course'], [], TRUE];
    yield 'courses and parties' => [['course', 'party'], [11], TRUE];
    yield 'order follows the type keys' => [['external_party', 'party'], [12, 11], FALSE];
    yield 'duplicates are removed' => [['party', 'party'], [11], FALSE];
    yield 'unknown keys are ignored' => [['party', 'unknown', 'course'], [11], TRUE];
    yield 'empty selection' => [[], [], FALSE];
    yield 'unchecked checkboxes' => [['party' => 0, 'external_party' => 0], [], FALSE];
    yield 'non string values' => [[1, NULL, ['party']], [], FALSE];
  }

  /**
   * Tests converting legacy type keys.
   *
   * @param array<mixed> $types
   *   The stored type keys.
   * @param int[] $organizers
   *   The expected organizer term IDs.
   * @param bool $courses
   *   The expected courses flag.
   */
  #[DataProvider('typesProvider')]
  public function testConvertTypes(array $types, array $organizers, bool $courses): void {
    $settings = BlockSettingsMigration::convertTypes($types, [
      'party' => 11,
      'external_party' => 12,
    ]);
    self::assertSame([
      'organizers' => $organizers,
      'courses' => $courses,
    ], $settings);
  }

  /**
   * Tests that an unresolved organizer is left out.
   */
  public function testConvertTypesWithoutResolvedOrganizer(): void {
    $settings = BlockSettingsMigration::convertTypes(['party', 'course'], [
      'party' => 0,
    ]);
    self::assertSame([
      'organizers' => [],
      'courses' => TRUE,
    ], $settings);
  }

}
