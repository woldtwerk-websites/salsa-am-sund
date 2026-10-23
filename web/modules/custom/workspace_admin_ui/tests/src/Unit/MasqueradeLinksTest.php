<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_admin_ui\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\masquerade\Masquerade;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Drupal\workspace_admin_ui\MasqueradeLinks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Tests which masquerade shortcuts are available.
 *
 * @see \Drupal\workspace_admin_ui\MasqueradeLinks
 */
#[Group('workspace_admin_ui')]
#[CoversClass(MasqueradeLinks::class)]
class MasqueradeLinksTest extends UnitTestCase {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The account storage.
   */
  protected EntityStorageInterface $storage;

  /**
   * The accounts by name.
   *
   * @var array<string, \Drupal\user\UserInterface>
   */
  protected array $accounts = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The access helper calls masquerade_target_user_access(), which a unit
    // test does not load with the rest of the module.
    if (!function_exists('masquerade_target_user_access')) {
      require_once DRUPAL_ROOT . '/modules/contrib/masquerade/masquerade.module';
    }

    $this->storage = $this->createMock(EntityStorageInterface::class);
    $this->storage->method('loadByProperties')->willReturnCallback(
      fn (array $properties): array => isset($this->accounts[$properties['name']])
        ? [$this->accounts[$properties['name']]]
        : [],
    );

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')
      ->with('user')
      ->willReturn($this->storage);

    $this->accounts = [
      MasqueradeLinks::USER => $this->account(4, 'user'),
      MasqueradeLinks::EDITOR => $this->account(5, 'editor'),
    ];
  }

  /**
   * Tests both accounts are offered while masquerading is allowed.
   */
  public function testBothAccountsAvailable(): void {
    $this->configureDrupal();

    $available = $this->links()->available();

    $this->assertSame(['user', 'editor'], array_keys($available));
    $this->assertSame(4, $available['user']->id());
    $this->assertSame(5, $available['editor']->id());
  }

  /**
   * Tests an account is left out when no account has the name.
   */
  public function testMissingAccount(): void {
    $this->configureDrupal();
    unset($this->accounts[MasqueradeLinks::EDITOR]);

    $this->assertSame(['user'], array_keys($this->links()->available()));
    $this->assertNull($this->links()->account(MasqueradeLinks::EDITOR));
  }

  /**
   * Tests the account lookup is remembered for the request.
   */
  public function testAccountLookupIsCached(): void {
    $this->configureDrupal();
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('loadByProperties')
      ->with(['name' => MasqueradeLinks::USER])
      ->willReturn([$this->accounts[MasqueradeLinks::USER]]);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($storage);

    $links = new MasqueradeLinks($entityTypeManager);
    $this->assertNotNull($links->account(MasqueradeLinks::USER));
    $this->assertNotNull($links->account(MasqueradeLinks::USER));
  }

  /**
   * Tests the cache tags cover the list and every account.
   */
  public function testCacheTags(): void {
    $this->configureDrupal();
    // Make sure both accounts are known before the tags are gathered.
    $links = $this->links();
    $links->available();

    $this->assertEqualsCanonicalizing(
      ['user_list', 'user:4', 'user:5'],
      $links->cacheTags(),
    );
  }

  /**
   * Sets the services masquerade_target_user_access() reaches for.
   */
  protected function configureDrupal(): void {
    $currentUser = $this->createMock(AccountInterface::class);
    $currentUser->method('id')->willReturn(1);

    $masquerade = $this->createMock(Masquerade::class);
    $masquerade->method('isMasquerading')->willReturn(FALSE);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('invokeAll')
      ->with('masquerade_access')
      ->willReturn([TRUE]);

    $container = new ContainerBuilder();
    $container->set('current_user', $currentUser);
    $container->set('masquerade', $masquerade);
    $container->set('module_handler', $moduleHandler);
    $container->set('request_stack', new RequestStack());
    $container->set('session', $this->createMock(SessionInterface::class));
    \Drupal::setContainer($container);
  }

  /**
   * Builds the link helper.
   *
   * @return \Drupal\workspace_admin_ui\MasqueradeLinks
   *   The helper.
   */
  protected function links(): MasqueradeLinks {
    return new MasqueradeLinks($this->entityTypeManager);
  }

  /**
   * Builds a user account mock.
   *
   * @param int $id
   *   The user id.
   * @param string $name
   *   The user name.
   *
   * @return \Drupal\user\UserInterface
   *   The account.
   */
  protected function account(int $id, string $name): UserInterface {
    $account = $this->createMock(UserInterface::class);
    $account->method('id')->willReturn($id);
    $account->method('getAccountName')->willReturn($name);
    $account->method('getCacheTags')->willReturn(['user:' . $id]);

    return $account;
  }

}
