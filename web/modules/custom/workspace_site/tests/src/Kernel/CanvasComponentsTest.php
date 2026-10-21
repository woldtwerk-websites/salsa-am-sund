<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_site\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\contact\Entity\ContactForm;
use Drupal\Core\Routing\RouteMatch;
use Drupal\workspace_site\PageCommentsResolver;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Routing\Route;

/**
 * Tests that the block plugins of this module are Canvas components.
 */
#[CoversNothing]
#[Group('workspace_site')]
#[RunTestsInSeparateProcesses]
final class CanvasComponentsTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'contact',
    'comment',
    'workspace_site',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['contact']);
    ContactForm::create([
      'id' => 'kontakt',
      'label' => 'Kontakt',
      'recipients' => ['info@example.com'],
      'message' => 'Thank you for your message.',
    ])->save();
  }

  /**
   * Tests that both block plugins are discovered as enabled components.
   */
  public function testBlocksAreDiscoveredAsComponents(): void {
    // Trigger Canvas component discovery, like a cache rebuild would.
    $this->container->get(ComponentSourceManager::class)->generateComponents();

    foreach (['block.salsa_contact_form', 'block.salsa_page_comments'] as $id) {
      $component = Component::load($id);
      self::assertInstanceOf(Component::class, $component, "The component $id was not discovered.");
      self::assertTrue($component->status(), "The component $id is not enabled.");
      self::assertSame('workspace_site', $component->get('provider'));
      self::assertSame('block', $component->get('source'));
    }
  }

  /**
   * Tests that the resolver finds the page on the canonical page route.
   */
  public function testResolverResolvesCanvasPage(): void {
    $page = Page::create(['title' => 'Test page']);
    $route_match = new RouteMatch(
      'entity.canvas_page.canonical',
      new Route('/page/{canvas_page}'),
      ['canvas_page' => $page],
    );

    $resolver = $this->container->get(PageCommentsResolver::class);
    self::assertSame($page, $resolver->resolvePage($route_match));
    self::assertFalse($resolver->shouldDisplayPlaceholder($route_match));
  }

}
