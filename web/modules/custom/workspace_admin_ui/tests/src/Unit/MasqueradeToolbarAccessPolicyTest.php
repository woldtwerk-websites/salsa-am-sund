<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_admin_ui\Unit;

use Drupal\workspace_admin_ui\Access\MasqueradeToolbarAccessPolicy;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccessPolicyInterface;
use Drupal\Core\Site\Settings;
use Drupal\masquerade\Masquerade;
use Drupal\masquerade\Session\MetadataBag;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests the masquerade toolbar access policy.
 *
 * @group workspace_admin_ui
 *
 * @coversDefaultClass \Drupal\workspace_admin_ui\Access\MasqueradeToolbarAccessPolicy
 */
class MasqueradeToolbarAccessPolicyTest extends UnitTestCase {

  /**
   * The masquerade service.
   */
  protected Masquerade $masquerade;

  /**
   * The session.
   */
  protected SessionInterface $session;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Cache::mergeContexts(), used by the parent policy, asserts the contexts
    // through the cache contexts manager.
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);
  }

  /**
   * Builds the policy for a request stack.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   *
   * @return \Drupal\workspace_admin_ui\Access\MasqueradeToolbarAccessPolicy
   *   The policy.
   */
  protected function policy(RequestStack $requestStack): MasqueradeToolbarAccessPolicy {
    return new MasqueradeToolbarAccessPolicy($this->masquerade, $this->session, $requestStack);
  }

  /**
   * Creates a request with a masquerade-backed session.
   *
   * @return \Symfony\Component\HttpFoundation\RequestStack
   *   A stack holding the request.
   */
  protected function stackWithMasqueradeSession(): RequestStack {
    $storage = new MockArraySessionStorage();
    $storage->setMetadataBag(new MetadataBag(new Settings([])));
    $request = Request::create('/');
    $request->setSession(new Session($storage));

    $requestStack = new RequestStack();
    $requestStack->push($request);

    return $requestStack;
  }

  /**
   * Creates a request with a plain Symfony session, as Drush uses.
   *
   * @return \Symfony\Component\HttpFoundation\RequestStack
   *   A stack holding the request.
   */
  protected function stackWithCoreSession(): RequestStack {
    $request = Request::create('/');
    $request->setSession(new Session(new MockArraySessionStorage()));

    $requestStack = new RequestStack();
    $requestStack->push($request);

    return $requestStack;
  }

  /**
   * Tests the persistent cache context only appears for a web session.
   *
   * @covers ::getPersistentCacheContexts
   */
  public function testPersistentCacheContexts(): void {
    $this->masquerade = $this->createMock(Masquerade::class);
    $this->session = $this->createMock(SessionInterface::class);

    $this->assertSame(
      ['session.is_masquerading'],
      $this->policy($this->stackWithMasqueradeSession())->getPersistentCacheContexts(),
    );
    $this->assertSame(
      [],
      $this->policy($this->stackWithCoreSession())->getPersistentCacheContexts(),
    );
    $this->assertSame(
      [],
      $this->policy(new RequestStack())->getPersistentCacheContexts(),
    );
  }

  /**
   * Tests no permissions are calculated without a masquerade web session.
   *
   * @covers ::calculatePermissions
   */
  public function testNoPermissionsWithoutMasqueradeSession(): void {
    $this->masquerade = $this->createMock(Masquerade::class);
    $this->masquerade->expects($this->never())->method('isMasquerading');
    $this->session = $this->createMock(SessionInterface::class);

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(7);

    $permissions = $this->policy($this->stackWithCoreSession())
      ->calculatePermissions($account, AccessPolicyInterface::SCOPE_DRUPAL);

    $this->assertFalse($permissions->getItem());
    $this->assertNotContains('user', $permissions->getCacheContexts());
  }

  /**
   * Tests the masqueraded account gets the navigation permissions.
   *
   * @covers ::calculatePermissions
   */
  public function testPermissionForMasqueradedAccount(): void {
    $this->masquerade = $this->createMock(Masquerade::class);
    $this->masquerade->method('isMasquerading')->willReturn(TRUE);
    $this->session = $this->createMock(SessionInterface::class);
    $this->session->method('get')->with('uid')->willReturn('7');

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(7);

    $permissions = $this->policy($this->stackWithMasqueradeSession())
      ->calculatePermissions($account, AccessPolicyInterface::SCOPE_DRUPAL);

    $this->assertTrue($permissions->getItem()->hasPermission('access toolbar'));
    $this->assertTrue($permissions->getItem()->hasPermission('access navigation'));
    $this->assertContains('user', $permissions->getCacheContexts());
    $this->assertContains('session.is_masquerading', $permissions->getCacheContexts());
  }

  /**
   * Tests other accounts are left alone while masquerading.
   *
   * @covers ::calculatePermissions
   */
  public function testPermissionForOtherAccount(): void {
    $this->masquerade = $this->createMock(Masquerade::class);
    $this->masquerade->method('isMasquerading')->willReturn(TRUE);
    $this->session = $this->createMock(SessionInterface::class);
    $this->session->method('get')->with('uid')->willReturn('7');

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(8);

    $permissions = $this->policy($this->stackWithMasqueradeSession())
      ->calculatePermissions($account, AccessPolicyInterface::SCOPE_DRUPAL);

    $this->assertFalse($permissions->getItem());
    $this->assertContains('user', $permissions->getCacheContexts());
  }

}
