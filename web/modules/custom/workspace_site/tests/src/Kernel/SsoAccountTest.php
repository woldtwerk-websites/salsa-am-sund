<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_site\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\workspace_site\SsoAccount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the email synchronization of SSO accounts.
 */
#[CoversClass(SsoAccount::class)]
#[Group('workspace_site')]
#[RunTestsInSeparateProcesses]
final class SsoAccountTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'workspace_site',
  ];

  /**
   * The SSO account helper.
   */
  private SsoAccount $ssoAccount;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('user');
    $this->installConfig(['user']);

    $this->ssoAccount = $this->container->get(SsoAccount::class);
  }

  /**
   * Tests when the provider may change the email address.
   */
  public function testSyncMail(): void {
    $account = User::create([
      'name' => 'github-user',
      'mail' => 'old@example.com',
    ]);
    $account->save();
    $this->ssoAccount->markCreatedBy($account, 'github');

    // A new, unused and valid address of the creating provider is applied.
    self::assertTrue($this->ssoAccount->syncMail($account, 'github', 'new@example.com'));
    self::assertSame('new@example.com', $account->getEmail());
    self::assertFalse($this->ssoAccount->syncMail($account, 'github', 'new@example.com'));

    // Another provider must not change the address.
    self::assertFalse($this->ssoAccount->syncMail($account, 'google', 'google@example.com'));
    self::assertSame('new@example.com', $account->getEmail());

    // An address that belongs to another account is ignored.
    $other = User::create([
      'name' => 'other-user',
      'mail' => 'taken@example.com',
    ]);
    $other->save();
    self::assertFalse($this->ssoAccount->syncMail($account, 'github', 'taken@example.com'));
    self::assertSame('new@example.com', $account->getEmail());

    // Invalid addresses are ignored.
    self::assertFalse($this->ssoAccount->syncMail($account, 'github', 'not-an-email'));
    self::assertSame('new@example.com', $account->getEmail());

    // An account that was not created by a provider is never changed.
    $unmarked = User::create([
      'name' => 'form-user',
      'mail' => 'form@example.com',
    ]);
    $unmarked->save();
    self::assertNull($this->ssoAccount->getCreatingProvider($unmarked));
    self::assertFalse($this->ssoAccount->syncMail($unmarked, 'github', 'sso@example.com'));
    self::assertSame('form@example.com', $unmarked->getEmail());
  }

}
