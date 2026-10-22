<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\workflows\Entity\Workflow;
use Drupal\workspace_events\OrganizerResolver;
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
    self::assertTrue($role->hasPermission('create course content'));
    self::assertTrue($role->hasPermission('edit any course content'));
    self::assertTrue($role->hasPermission('delete any course content'));
    self::assertTrue($role->hasPermission('create terms in organizer'));
    self::assertTrue($role->hasPermission('edit terms in organizer'));
    self::assertTrue($role->hasPermission('delete terms in organizer'));

    workspace_events_uninstall();

    $role = Role::load('content_editor');
    self::assertInstanceOf(RoleInterface::class, $role);
    self::assertFalse($role->hasPermission('make smart dates recur'));
    self::assertFalse($role->hasPermission('create event content'));
    self::assertFalse($role->hasPermission('create course content'));
    self::assertFalse($role->hasPermission('create terms in organizer'));
  }

  /**
   * Tests that the organizer update hook creates the terms once.
   */
  public function testOrganizerUpdateHook(): void {
    $role = Role::create([
      'id' => 'content_editor',
      'label' => 'Content editor',
    ]);
    $role->save();

    // Simulate a site that installed the module before the organizer existed.
    foreach ($this->termStorage->loadByProperties(['vid' => OrganizerResolver::VOCABULARY]) as $term) {
      $term->delete();
    }
    $vocabulary = Vocabulary::load(OrganizerResolver::VOCABULARY);
    self::assertInstanceOf(Vocabulary::class, $vocabulary);
    $vocabulary->delete();

    // Deleting the storage also deletes the field instance.
    $storage = FieldStorageConfig::loadByName('node', 'field_organizer');
    self::assertInstanceOf(FieldStorageConfig::class, $storage);
    $storage->delete();
    self::assertNull(FieldConfig::loadByName('node', 'event', 'field_organizer'));

    \Drupal::moduleHandler()->loadInclude('workspace_events', 'install');
    workspace_events_update_10002();
    // A second run must not create duplicates.
    workspace_events_update_10002();

    self::assertInstanceOf(Vocabulary::class, Vocabulary::load(OrganizerResolver::VOCABULARY));
    self::assertInstanceOf(FieldConfig::class, FieldConfig::loadByName('node', 'event', 'field_organizer'));
    self::assertInstanceOf(FieldStorageConfig::class, FieldStorageConfig::loadByName('node', 'field_organizer'));
    $terms = $this->termStorage->loadByProperties(['vid' => OrganizerResolver::VOCABULARY]);
    self::assertCount(2, $terms);

    $by_uuid = [];
    foreach ($terms as $term) {
      \assert($term instanceof TermInterface);
      $by_uuid[(string) $term->uuid()] = $term;
    }
    self::assertArrayHasKey(OrganizerResolver::SALSA_AM_SUND_UUID, $by_uuid);
    self::assertArrayHasKey(OrganizerResolver::EXTERNAL_UUID, $by_uuid);
    self::assertSame('Salsa am Sund', (string) $by_uuid[OrganizerResolver::SALSA_AM_SUND_UUID]->label());
    self::assertSame(0, $by_uuid[OrganizerResolver::SALSA_AM_SUND_UUID]->getWeight());
    self::assertSame('Sonstige', (string) $by_uuid[OrganizerResolver::EXTERNAL_UUID]->label());
    self::assertSame(1, $by_uuid[OrganizerResolver::EXTERNAL_UUID]->getWeight());

    $role = Role::load('content_editor');
    self::assertInstanceOf(RoleInterface::class, $role);
    self::assertTrue($role->hasPermission('create terms in organizer'));
  }

  /**
   * Tests that the install hook creates the organizer terms.
   */
  public function testOrganizerInstallHook(): void {
    foreach ($this->termStorage->loadByProperties(['vid' => OrganizerResolver::VOCABULARY]) as $term) {
      $term->delete();
    }

    \Drupal::moduleHandler()->loadInclude('workspace_events', 'install');
    workspace_events_install();
    // A second run must not create duplicates.
    workspace_events_install();

    $terms = $this->termStorage->loadByProperties(['vid' => OrganizerResolver::VOCABULARY]);
    self::assertCount(2, $terms);
  }

  /**
   * Tests that the rename update hook keeps a label an editor changed.
   */
  public function testExternalOrganizerRename(): void {
    \Drupal::moduleHandler()->loadInclude('workspace_events', 'install');

    // Sites that installed the module before the rename still have "Extern".
    $external = $this->loadExternalOrganizer();
    $external->setName('Extern');
    $external->save();
    workspace_events_rename_external_organizer();
    self::assertSame('Sonstige', (string) $this->loadExternalOrganizer()->label());
    // A second run does not rename again.
    workspace_events_rename_external_organizer();
    self::assertSame('Sonstige', (string) $this->loadExternalOrganizer()->label());

    // A label an editor changed is kept.
    $external = $this->loadExternalOrganizer();
    $external->setName('Gäste');
    $external->save();
    workspace_events_rename_external_organizer();
    self::assertSame('Gäste', (string) $this->loadExternalOrganizer()->label());
  }

  /**
   * Loads the fixed "Sonstige" organizer term.
   */
  private function loadExternalOrganizer(): TermInterface {
    $terms = $this->termStorage->loadByProperties(['uuid' => OrganizerResolver::EXTERNAL_UUID]);
    $term = reset($terms);
    self::assertInstanceOf(TermInterface::class, $term);
    return $term;
  }

  /**
   * Tests that the update hook adds the course bundle and its permissions.
   */
  public function testCourseUpdateHook(): void {
    $workflow = Workflow::create([
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
            'from' => ['draft'],
            'to' => 'published',
            'weight' => 0,
          ],
        ],
        'entity_types' => [
          'node' => ['event'],
        ],
        'default_moderation_state' => 'draft',
      ],
    ]);
    $workflow->save();

    $role = Role::create([
      'id' => 'content_editor',
      'label' => 'Content editor',
    ]);
    $role->save();

    \Drupal::moduleHandler()->loadInclude('workspace_events', 'install');
    workspace_events_update_10001();
    // A second run must not add the bundle twice.
    workspace_events_update_10001();

    $workflow = Workflow::load('basic_editorial');
    self::assertInstanceOf(Workflow::class, $workflow);
    $bundles = $workflow->getTypePlugin()->getConfiguration()['entity_types']['node'] ?? [];
    self::assertSame(['event', 'course'], array_values($bundles));

    $role = Role::load('content_editor');
    self::assertInstanceOf(RoleInterface::class, $role);
    self::assertTrue($role->hasPermission('create course content'));
    self::assertTrue($role->hasPermission('edit any course content'));
  }

}
