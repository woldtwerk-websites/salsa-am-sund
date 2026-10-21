<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the permissions granted by the module installation.
 */
#[CoversNothing]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class InstallPermissionsTest extends EventKernelTestBase {

  /**
   * Tests that content editors may make smart dates recur.
   */
  public function testContentEditorPermissions(): void {
    $role = Role::create([
      'id' => 'content_editor',
      'label' => 'Content editor',
    ]);
    $role->save();

    \Drupal::moduleHandler()->loadInclude('workspace_events', 'install');
    workspace_events_install();

    $role = Role::load('content_editor');
    self::assertInstanceOf(RoleInterface::class, $role);
    self::assertTrue($role->hasPermission('make smart dates recur'));
    self::assertTrue($role->hasPermission('create event content'));
    self::assertTrue($role->hasPermission('edit any event content'));

    workspace_events_uninstall();

    $role = Role::load('content_editor');
    self::assertInstanceOf(RoleInterface::class, $role);
    self::assertFalse($role->hasPermission('make smart dates recur'));
    self::assertFalse($role->hasPermission('create event content'));
  }

}
