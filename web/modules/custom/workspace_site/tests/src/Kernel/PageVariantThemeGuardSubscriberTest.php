<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_site\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\workspace_site\EventSubscriber\PageVariantThemeGuardSubscriber;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Routing\Route;

/**
 * Tests that the Canvas page variant is not used for non-default themes.
 */
#[CoversClass(PageVariantThemeGuardSubscriber::class)]
#[Group('workspace_site')]
#[RunTestsInSeparateProcesses]
final class PageVariantThemeGuardSubscriberTest extends CanvasKernelTestBase {

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

    // A second installed theme that can act as the non-default active theme.
    $theme_installer = $this->container->get(ThemeInstallerInterface::class);
    \assert($theme_installer instanceof ThemeInstallerInterface);
    $theme_installer->install(['claro']);
    $this->config('system.theme')->set('default', 'stark')->save();

    // A site default page variant, so Canvas selects its page variant like it
    // does on the site.
    $marker = Component::load(Marker::PAGE_CONTENT_COMPONENT_ID);
    \assert($marker instanceof Component);
    PageVariant::create([
      'id' => 'site_default',
      'label' => 'Site default',
      'component_tree' => [
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => Marker::PAGE_CONTENT_COMPONENT_ID,
          'component_version' => $marker->getActiveVersion(),
          'inputs' => [],
        ],
      ],
    ])->save();
    $this->config('canvas.settings')
      ->set(PageVariant::DEFAULT_SETTING, 'site_default')
      ->save();
  }

  /**
   * Tests that a non-default active theme falls back to the block page.
   */
  public function testCanvasVariantIsReplacedForNonDefaultTheme(): void {
    $this->setActiveTheme('claro');

    $event = $this->selectPageDisplayVariant();

    self::assertSame('block_page', $event->getPluginId());
    self::assertSame([], $event->getPluginConfiguration());
    self::assertContains('theme', $event->getCacheContexts());
    self::assertContains('config:system.theme', $event->getCacheTags());
  }

  /**
   * Tests that the Canvas variant is kept for the default theme.
   */
  public function testCanvasVariantIsKeptForDefaultTheme(): void {
    $this->setActiveTheme('stark');

    $event = $this->selectPageDisplayVariant();

    self::assertSame(CanvasPageVariant::PLUGIN_ID, $event->getPluginId());
    self::assertContains('theme', $event->getCacheContexts());
    self::assertContains('config:system.theme', $event->getCacheTags());
  }

  /**
   * Dispatches a page display variant selection event.
   *
   * @return \Drupal\Core\Render\PageDisplayVariantSelectionEvent
   *   The event after all subscribers have processed it.
   */
  private function selectPageDisplayVariant(): PageDisplayVariantSelectionEvent {
    $event = new PageDisplayVariantSelectionEvent('simple_page', new RouteMatch('user.login', new Route('/user/login')));
    $this->container->get('event_dispatcher')->dispatch($event, RenderEvents::SELECT_PAGE_DISPLAY_VARIANT);
    return $event;
  }

  /**
   * Sets the active theme without running the theme negotiators.
   *
   * @param string $theme
   *   The machine name of an installed theme.
   */
  private function setActiveTheme(string $theme): void {
    $theme_manager = $this->container->get(ThemeManagerInterface::class);
    \assert($theme_manager instanceof ThemeManagerInterface);
    $theme_initialization = $this->container->get(ThemeInitializationInterface::class);
    \assert($theme_initialization instanceof ThemeInitializationInterface);
    $theme_manager->setActiveTheme($theme_initialization->getActiveThemeByName($theme));
  }

}
