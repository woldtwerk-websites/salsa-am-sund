<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_site\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\workspace_site\Hook\UsernameHooks;
use Drupal\workspace_site\UsernameGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the generated user names.
 */
#[CoversClass(UsernameHooks::class)]
#[CoversClass(UsernameGenerator::class)]
#[Group('workspace_site')]
#[RunTestsInSeparateProcesses]
final class UsernameHooksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'workspace_site',
  ];

  /**
   * The user name generator.
   */
  private UsernameGenerator $generator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('user');
    $this->installConfig(['user']);
    $this->createNameField('field_first_name', 'First name');
    $this->createNameField('field_last_name', 'Last name');

    $this->generator = $this->container->get(UsernameGenerator::class);
  }

  /**
   * Tests generation, cleaning, renames and collision handling.
   */
  public function testGeneratedUsernames(): void {
    $first = User::create([
      'name' => 'placeholder-first',
      'mail' => 'max@example.com',
      'field_first_name' => 'Max',
      'field_last_name' => 'Müller',
    ]);
    $first->save();
    self::assertSame('Max Müller', $first->getAccountName());

    $second = User::create([
      'name' => 'placeholder-second',
      'mail' => 'max2@example.com',
      'field_first_name' => 'Max',
      'field_last_name' => 'Müller',
    ]);
    $second->save();
    self::assertSame('Max Müller 2', $second->getAccountName());

    $cleaned = User::create([
      'name' => 'placeholder-cleaned',
      'mail' => 'erika@example.com',
      'field_first_name' => '  Erika  ',
      'field_last_name' => ' Muster#mann! ',
    ]);
    $cleaned->save();
    self::assertSame('Erika Mustermann', $cleaned->getAccountName());

    $international = User::create([
      'name' => 'placeholder-international',
      'mail' => 'lukasz@example.com',
      'field_first_name' => 'Łukasz',
      'field_last_name' => 'Żółć',
    ]);
    $international->save();
    self::assertSame('Łukasz Żółć', $international->getAccountName());

    // The user being renamed is ignored when checking for collisions.
    self::assertSame('Max Müller', $this->generator->generate('Max', 'Müller', (int) $first->id()));

    $second->set('field_last_name', 'Meier');
    $second->save();
    self::assertSame('Max Meier', $second->getAccountName());

    // Re-saving without a name change keeps the user name.
    $second->save();
    self::assertSame('Max Meier', $second->getAccountName());

    // An unchanged user does not get a suffix because of its own name.
    $first->save();
    self::assertSame('Max Müller', $first->getAccountName());
  }

  /**
   * Creates a string field on the user entity.
   *
   * @param string $field_name
   *   The field name.
   * @param string $label
   *   The field label.
   */
  private function createNameField(string $field_name, string $label): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'user',
      'type' => 'string',
      'cardinality' => 1,
      'translatable' => FALSE,
      'settings' => [
        'max_length' => 255,
        'is_ascii' => FALSE,
        'case_sensitive' => FALSE,
      ],
    ])->save();

    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => $label,
      'field_storage' => FieldStorageConfig::loadByName('user', $field_name),
    ])->save();
  }

}
