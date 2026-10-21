<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_gallery\Kernel;

use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\workflows\Entity\Workflow;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the workflow and permissions changes made during installation.
 */
#[CoversNothing]
#[Group('workspace_gallery')]
#[RunTestsInSeparateProcesses]
final class InstallTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'field',
    'node',
    'content_moderation',
    'workflows',
    'pathauto',
    'token',
    'workspace_gallery',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('path_alias');

    NodeType::create([
      'type' => 'gallery',
      'name' => 'Fotogalerie',
    ])->save();

    Workflow::create([
      'id' => 'basic_editorial',
      'label' => 'Basis',
      'type' => 'content_moderation',
      'type_settings' => [
        'states' => [
          'draft' => [
            'label' => 'Entwurf',
            'weight' => -1,
            'published' => FALSE,
            'default_revision' => FALSE,
          ],
          'published' => [
            'label' => 'Veröffentlicht',
            'weight' => 0,
            'published' => TRUE,
            'default_revision' => TRUE,
          ],
        ],
        'transitions' => [
          'publish' => [
            'label' => 'Veröffentlichen',
            'from' => ['draft', 'published'],
            'to' => 'published',
            'weight' => 0,
          ],
        ],
        'entity_types' => [
          'node' => [],
        ],
        'default_moderation_state' => 'draft',
      ],
    ])->save();

    Role::create([
      'id' => 'content_editor',
      'label' => 'Content editor',
    ])->save();
  }

  /**
   * Tests that installing adds the bundle and grants the permissions.
   */
  public function testInstallAddsBundleAndPermissions(): void {
    \Drupal::moduleHandler()->loadInclude('workspace_gallery', 'install');
    workspace_gallery_install();

    $workflow = Workflow::load('basic_editorial');
    self::assertInstanceOf(Workflow::class, $workflow);
    $configuration = $workflow->getTypePlugin()->getConfiguration();
    self::assertContains('gallery', $configuration['entity_types']['node']);

    $role = Role::load('content_editor');
    self::assertInstanceOf(RoleInterface::class, $role);
    self::assertTrue($role->hasPermission('create gallery content'));
    self::assertTrue($role->hasPermission('edit any gallery content'));
    self::assertTrue($role->hasPermission('delete any gallery content'));
  }

  /**
   * Tests that uninstalling removes the bundle and revokes the permissions.
   */
  public function testUninstallRemovesBundleAndPermissions(): void {
    \Drupal::moduleHandler()->loadInclude('workspace_gallery', 'install');
    workspace_gallery_install();
    workspace_gallery_uninstall();

    $workflow = Workflow::load('basic_editorial');
    self::assertInstanceOf(Workflow::class, $workflow);
    $configuration = $workflow->getTypePlugin()->getConfiguration();
    self::assertNotContains('gallery', $configuration['entity_types']['node']);

    $role = Role::load('content_editor');
    self::assertInstanceOf(RoleInterface::class, $role);
    self::assertFalse($role->hasPermission('create gallery content'));
    self::assertFalse($role->hasPermission('edit any gallery content'));
    self::assertFalse($role->hasPermission('delete any gallery content'));
  }

}
