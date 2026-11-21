<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Tests that update 10014 only removes the stale filter checkbox violations.
 */
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class StaleCanvasFormViolationsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Tests stale violations are dropped and unrelated ones are kept.
   */
  public function testUpdateRemovesOnlyStaleViolations(): void {
    require_once dirname(__DIR__, 3) . '/workspace_events.install';

    $store = $this->container->get('keyvalue')->get('canvas.component_instance_form_violations');
    $store->set('only-stale', new ConstraintViolationList([
      $this->violation('organizers'),
      $this->violation('locations'),
    ]));
    $store->set('mixed', new ConstraintViolationList([
      $this->violation('locations'),
      $this->violation('label'),
    ]));
    $store->set('unrelated', new ConstraintViolationList([$this->violation('limit')]));

    workspace_events_update_10014();

    $this->assertNull($store->get('only-stale'));
    $this->assertSame(['label'], $this->paths($store->get('mixed')));
    $this->assertSame(['limit'], $this->paths($store->get('unrelated')));
  }

  /**
   * Creates a violation on the given property path.
   */
  private function violation(string $path): ConstraintViolation {
    return new ConstraintViolation('The submitted value 0 is not allowed.', 'template', [], NULL, $path, 0);
  }

  /**
   * Returns the property paths of a violation list.
   *
   * @return string[]
   *   The property paths.
   */
  private function paths(ConstraintViolationList $list): array {
    $paths = [];
    foreach ($list as $violation) {
      $paths[] = $violation->getPropertyPath();
    }
    return $paths;
  }

}
