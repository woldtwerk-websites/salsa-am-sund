<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_admin_ui\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the access gate on masquerade's switch route.
 *
 * @see \Drupal\workspace_admin_ui\Routing\RouteSubscriber
 * @see \Drupal\workspace_admin_ui\Access\MasqueradeTargetAccessCheck
 */
#[Group('workspace_admin_ui')]
#[RunTestsInSeparateProcesses]
class MasqueradeRouteAccessTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'masquerade',
    'workspace_admin_ui',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['user']);

    // Masquerade lets uid 1 switch to anyone, so create that account first:
    // the accounts under test then get other ids.
    User::create(['name' => 'root', 'status' => 1])->save();
  }

  /**
   * Tests the route carries the access check as a requirement.
   */
  public function testRouteRequirement(): void {
    $route = $this->container->get('router.route_provider')
      ->getRouteByName('entity.user.masquerade');

    // Pins the gate at route level: without the requirement the access check
    // never runs, even though the route still refuses some requests.
    $this->assertSame('TRUE', $route->getRequirement('_workspace_admin_ui_masquerade_target_access'));
  }

  /**
   * Tests a user without the masquerade permission is refused.
   */
  public function testAccessDeniedWithoutPermission(): void {
    $actor = $this->createUser();
    $target = $this->createUser();

    $this->assertFalse($this->checkRoute($actor, $target->id()));
  }

  /**
   * Tests a user with the masquerade permission is allowed.
   */
  public function testAccessAllowedWithPermission(): void {
    $actor = $this->createUser(['masquerade as any user']);
    $target = $this->createUser();

    $this->assertTrue($this->checkRoute($actor, $target->id()));
  }

  /**
   * Checks the masquerade route as an account.
   *
   * @param \Drupal\user\UserInterface $actor
   *   The account making the request.
   * @param int|string $target
   *   The uid to masquerade as.
   *
   * @return bool
   *   Whether the route is accessible.
   */
  protected function checkRoute(UserInterface $actor, int|string $target): bool {
    // The access check delegates to masquerade_target_user_access(), which
    // reads the current user from the container, so switch it like a request
    // would. The CSRF check needs the incoming request, and the access manager
    // drops it when none is passed.
    $this->setCurrentUser($actor);

    return (bool) $this->container->get('access_manager')
      ->checkNamedRoute('entity.user.masquerade', ['user' => $target], $actor);
  }

}
