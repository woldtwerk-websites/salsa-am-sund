<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_admin_ui\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the masquerade links the module adds to the Navigation user menu.
 *
 * @see web/modules/custom/workspace_admin_ui/workspace_admin_ui.links.menu.yml
 * @see \Drupal\workspace_admin_ui\Plugin\Menu\MasqueradeMenuLink
 */
#[Group('workspace_admin_ui')]
#[RunTestsInSeparateProcesses]
class NavigationMenuLinksTest extends KernelTestBase {

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
    // getDefinition() reads the menu tree storage, which a real install fills.
    $this->container->get('plugin.manager.menu.link')->rebuild();
  }

  /**
   * Tests the route parameter is the uid of the account with the name.
   */
  public function testRouteParameterResolvesAccountName(): void {
    $account = User::create(['name' => 'user', 'status' => 1]);
    $account->save();

    $link = $this->container->get('plugin.manager.menu.link')
      ->createInstance('workspace_admin_ui.masquerade.user');

    $this->assertInstanceOf('Drupal\workspace_admin_ui\Plugin\Menu\MasqueradeMenuLink', $link);
    $this->assertSame(['user' => $account->id()], $link->getRouteParameters());
  }

  /**
   * Tests a missing account resolves to a uid no account holds.
   */
  public function testMissingAccountResolvesToInvalidUid(): void {
    $link = $this->container->get('plugin.manager.menu.link')
      ->createInstance('workspace_admin_ui.masquerade.editor');

    $this->assertSame(['user' => 0], $link->getRouteParameters());
  }

  /**
   * Tests the link's cache metadata covers the accounts and the masquerade.
   */
  public function testCacheMetadata(): void {
    $account = User::create(['name' => 'user', 'status' => 1]);
    $account->save();

    $link = $this->container->get('plugin.manager.menu.link')
      ->createInstance('workspace_admin_ui.masquerade.user');

    $this->assertContains('user:' . $account->id(), $link->getCacheTags());
  }

}
