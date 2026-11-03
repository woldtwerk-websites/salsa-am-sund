<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_site\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Drupal\workspace_site\Access\RegistrationApprovalAccess;
use Drupal\workspace_site\Controller\ApproveRegistrationController;
use Drupal\workspace_site\Hook\AwaitingApprovalHooks;
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

  use UserCreationTrait;

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

    // A blocked account that an administrator blocked is not pending: it does
    // not carry the marker of a self-registration.
    $blocked = $this->createAccount('blocked', 1);
    $blocked->set('status', 0);
    $blocked->save();
    $this->pendingRegistration->mark($blocked);
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
   * Tests approving a pending registration.
   */
  public function testApproveOperation(): void {
    // The visitor registers while anonymous, so the presave hook marks the
    // blocked account as awaiting approval.
    $pending = $this->createAccount('pending', 0);

    $admin = $this->createUser(['administer users']);
    $this->setCurrentUser($admin);

    // An administrator created this account blocked while signed in, so it is
    // not a registration.
    $blocked_by_admin = $this->createAccount('blocked_by_admin', 0);

    // Blocking an approved account later does not make it pending again.
    $blocked_after_approval = $this->createAccount('blocked_after_approval', 1);
    $blocked_after_approval->set('status', 0);
    $blocked_after_approval->save();

    $anonymous = User::getAnonymousUser();

    self::assertTrue($this->pendingRegistration->isPending($pending));
    self::assertFalse($this->pendingRegistration->isPending($blocked_by_admin));
    self::assertFalse($this->pendingRegistration->isPending($blocked_after_approval));
    self::assertFalse($this->pendingRegistration->isPending($anonymous));

    // The marker is what tells the two blocked accounts apart.
    self::assertTrue((bool) $pending->get(AwaitingApprovalHooks::FIELD_NAME)->value);
    self::assertFalse((bool) $blocked_by_admin->get(AwaitingApprovalHooks::FIELD_NAME)->value);
    self::assertFalse((bool) $blocked_after_approval->get(AwaitingApprovalHooks::FIELD_NAME)->value);

    // Only the pending registration gets the approve operation, and it sorts
    // before the edit operation, so it is the primary dropbutton action.
    $list_builder = $this->container->get('entity_type.manager')->getListBuilder('user');
    $operations = $list_builder->getOperations($pending);
    self::assertSame('approve', array_key_first($operations));
    self::assertArrayHasKey('delete', $operations);
    foreach ([$blocked_by_admin, $blocked_after_approval, $anonymous] as $account) {
      self::assertArrayNotHasKey('approve', $list_builder->getOperations($account));
    }
    foreach ([$pending, $blocked_by_admin, $blocked_after_approval] as $account) {
      self::assertArrayHasKey('delete', $list_builder->getOperations($account));
    }

    // An editor with only the registration permission sees both operations on
    // the pending account, and the delete operation uses our confirm form.
    $editor = $this->createUser(['approve user registrations']);
    $this->setCurrentUser($editor);
    $operations = $list_builder->getOperations($pending);
    self::assertSame('approve', array_key_first($operations));
    self::assertArrayHasKey('delete', $operations);
    self::assertSame('workspace_site.delete_registration', $operations['delete']['url']->getRouteName());

    // The editor gets nothing from our hook for an account that is not pending.
    $operations = $list_builder->getOperations($blocked_by_admin);
    self::assertArrayNotHasKey('approve', $operations);
    self::assertArrayNotHasKey('delete', $operations);

    $access = $this->container->get(RegistrationApprovalAccess::class);
    self::assertInstanceOf(RegistrationApprovalAccess::class, $access);
    self::assertTrue($access->access($editor, $pending)->isAllowed());
    self::assertFalse($access->access($editor, $blocked_by_admin)->isAllowed());
    self::assertFalse($access->access($editor, $blocked_after_approval)->isAllowed());

    // A user without the permission gets neither operation.
    $this->setCurrentUser($this->createUser());
    $operations = $list_builder->getOperations($pending);
    self::assertArrayNotHasKey('approve', $operations);
    self::assertArrayNotHasKey('delete', $operations);

    $this->setCurrentUser($admin);
    $controller = $this->container->get('class_resolver')
      ->getInstanceFromDefinition(ApproveRegistrationController::class);
    self::assertInstanceOf(ApproveRegistrationController::class, $controller);
    $response = $controller->approve($pending);
    self::assertSame(302, $response->getStatusCode());

    $approved = User::load($pending->id());
    self::assertNotNull($approved);
    self::assertTrue($approved->isActive());
    self::assertFalse($this->pendingRegistration->isPending($approved));
    self::assertFalse((bool) $approved->get(AwaitingApprovalHooks::FIELD_NAME)->value);
    self::assertFalse($access->access($editor, $approved)->isAllowed());
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
