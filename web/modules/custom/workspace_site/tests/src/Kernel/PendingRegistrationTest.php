<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_site\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\workspace_site\PendingRegistration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests the marked pending registration.
 */
#[CoversClass(PendingRegistration::class)]
#[Group('workspace_site')]
#[RunTestsInSeparateProcesses]
final class PendingRegistrationTest extends KernelTestBase {

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
   * The pending registration tracker.
   */
  private PendingRegistration $pendingRegistration;

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

    $request = Request::create('/');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $this->pendingRegistration = $this->container->get(PendingRegistration::class);
  }

  /**
   * Tests which marked accounts count as a pending registration.
   */
  public function testMarkedAccount(): void {
    // Nothing marked yet.
    self::assertNull($this->pendingRegistration->getAccount());

    $pending = $this->createAccount('pending', 0);
    $this->pendingRegistration->mark($pending);
    $found = $this->pendingRegistration->getAccount();
    self::assertNotNull($found);
    self::assertSame($pending->id(), $found->id());
    self::assertTrue($this->pendingRegistration->needsName($found));

    // An account that has logged in before is not a pending registration.
    $logged_in = $this->createAccount('logged_in', 0);
    $logged_in->setLastLoginTime(1000);
    $logged_in->save();
    $this->pendingRegistration->mark($logged_in);
    self::assertNull($this->pendingRegistration->getAccount());

    // The marked account is ignored once it is activated.
    $this->pendingRegistration->mark($pending);
    $pending->set('status', 1);
    $pending->save();
    self::assertNull($this->pendingRegistration->getAccount());

    // A deleted account is ignored.
    $deleted = $this->createAccount('deleted', 0);
    $this->pendingRegistration->mark($deleted);
    $deleted->delete();
    self::assertNull($this->pendingRegistration->getAccount());

    // Clearing the marker removes the account.
    $marked = $this->createAccount('marked', 0);
    $this->pendingRegistration->mark($marked);
    self::assertNotNull($this->pendingRegistration->getAccount());
    $this->pendingRegistration->clear();
    self::assertNull($this->pendingRegistration->getAccount());
  }

  /**
   * Creates a blocked account without a name.
   *
   * @param string $name
   *   The user name.
   * @param int $status
   *   The account status.
   *
   * @return \Drupal\user\Entity\User
   *   The account.
   */
  private function createAccount(string $name, int $status): User {
    $account = User::create([
      'name' => $name,
      'mail' => $name . '@example.com',
      'status' => $status,
      'field_first_name' => '',
      'field_last_name' => '',
    ]);
    $account->save();

    return $account;
  }

  /**
   * Creates a string field on the user entity.
   *
   * @param string $field_name
   *   The machine name of the field.
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
