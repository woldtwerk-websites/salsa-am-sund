<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_admin_ui\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\masquerade\Masquerade;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Drupal\workspace_admin_ui\Access\MasqueradeTargetAccessCheck;
use Drupal\workspace_admin_ui\MasqueradeLinks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests the access check on masquerade's switch route.
 *
 * @see \Drupal\workspace_admin_ui\Access\MasqueradeTargetAccessCheck
 */
#[Group('workspace_admin_ui')]
#[CoversClass(MasqueradeTargetAccessCheck::class)]
class MasqueradeTargetAccessCheckTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    if (!function_exists('masquerade_target_user_access')) {
      require_once DRUPAL_ROOT . '/modules/contrib/masquerade/masquerade.module';
    }
  }

  /**
   * Tests access is allowed when masquerade allows the switch.
   */
  public function testAllowed(): void {
    $this->configureDrupal();

    $result = $this->accessCheck()->access($this->targetAccount(4));

    $this->assertTrue($result->isAllowed());
    $this->assertContains('session.is_masquerading', $result->getCacheContexts());
    $this->assertContains('user.permissions', $result->getCacheContexts());
    $this->assertContains('user:4', $result->getCacheTags());
  }

  /**
   * Tests access is forbidden when masquerade grants no permission.
   */
  public function testForbiddenWithoutPermission(): void {
    $this->configureDrupal(hookResults: []);

    $this->assertFalse($this->accessCheck()->access($this->targetAccount(4))->isAllowed());
  }

  /**
   * Sets the services masquerade_target_user_access() reaches for.
   *
   * @param array<int, bool|null> $hookResults
   *   What hook_masquerade_access() implementations return.
   */
  protected function configureDrupal(array $hookResults = [TRUE]): void {
    $currentUser = $this->createMock(AccountInterface::class);
    $currentUser->method('id')->willReturn(1);

    $masquerade = $this->createMock(Masquerade::class);
    $masquerade->method('isMasquerading')->willReturn(FALSE);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('invokeAll')->willReturn($hookResults);

    $container = new ContainerBuilder();
    $container->set('current_user', $currentUser);
    $container->set('masquerade', $masquerade);
    $container->set('module_handler', $moduleHandler);
    // AccessResult::addCacheContexts() validates the contexts.
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);
  }

  /**
   * Builds the access check.
   *
   * @return \Drupal\workspace_admin_ui\Access\MasqueradeTargetAccessCheck
   *   The access check.
   */
  protected function accessCheck(): MasqueradeTargetAccessCheck {
    return new MasqueradeTargetAccessCheck(
      new MasqueradeLinks($this->createMock(EntityTypeManagerInterface::class)),
    );
  }

  /**
   * Builds a target account mock.
   *
   * @param int $id
   *   The user id.
   *
   * @return \Drupal\user\UserInterface
   *   The account.
   */
  protected function targetAccount(int $id): UserInterface {
    $account = $this->createMock(UserInterface::class);
    $account->method('id')->willReturn($id);
    $account->method('getCacheTags')->willReturn(['user:' . $id]);
    $account->method('getCacheContexts')->willReturn([]);
    $account->method('getCacheMaxAge')->willReturn(-1);

    return $account;
  }

}
