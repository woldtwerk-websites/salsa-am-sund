<?php

declare(strict_types=1);

namespace Drupal\workspace_site\EventSubscriber;

use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Display\Attribute\PageDisplayVariant;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Drupal\Core\Theme\ThemeManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Suppresses the Canvas page chrome when a non-default theme renders the page.
 *
 * Canvas selects its page display variant for every front-end route that is
 * not an admin route. Routes like /user/login switch to the administration
 * theme through a theme negotiator (gin_login does this), and their theme
 * brings its own page template. Rendering the Canvas page variant there
 * replaces that template with the front-end theme's header and footer.
 *
 * This subscriber runs after Canvas' selection and falls back to core's block
 * page layout (or the simple page layout when Block is not installed), so the
 * negotiated theme's own page template is used again.
 *
 * @see \Drupal\canvas\EventSubscriber\PageVariantSelectorSubscriber
 */
final class PageVariantThemeGuardSubscriber implements EventSubscriberInterface {

  /**
   * The page display variant plugin ID of Drupal Canvas, if discoverable.
   */
  private readonly ?string $canvasPluginId;

  /**
   * Constructs a PageVariantThemeGuardSubscriber object.
   *
   * @param \Drupal\Core\Theme\ThemeManagerInterface $themeManager
   *   The theme manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    private readonly ThemeManagerInterface $themeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
    $this->canvasPluginId = self::getCanvasPageVariantPluginId();
  }

  /**
   * Switches away from the Canvas page variant for non-default themes.
   *
   * @param \Drupal\Core\Render\PageDisplayVariantSelectionEvent $event
   *   The page display variant selection event.
   */
  public function onSelectPageDisplayVariant(PageDisplayVariantSelectionEvent $event): void {
    $config = $this->configFactory->get('system.theme');
    $event->addCacheableDependency($config);
    $event->addCacheContexts(['theme']);

    if ($this->canvasPluginId === NULL) {
      return;
    }
    $default_theme = $config->get('default');
    if (!is_string($default_theme) || $default_theme === '') {
      return;
    }
    // Resolve the active theme for the route being rendered: the theme may not
    // have been initialized yet when no earlier renderer needed it.
    $active_theme = $this->themeManager->getActiveTheme($event->getRouteMatch())->getName();
    if (!self::shouldSuppressCanvasChrome((string) $event->getPluginId(), $this->canvasPluginId, $active_theme, $default_theme)) {
      return;
    }

    $event->setPluginId($this->moduleHandler->moduleExists('block') ? 'block_page' : 'simple_page');
    $event->setPluginConfiguration([]);
  }

  /**
   * Determines whether the Canvas page chrome must be suppressed.
   *
   * @param string $selected_plugin_id
   *   The page display variant plugin ID selected so far.
   * @param string $canvas_plugin_id
   *   The page display variant plugin ID of Drupal Canvas.
   * @param string $active_theme
   *   The machine name of the active theme.
   * @param string $default_theme
   *   The machine name of the site's default (front-end) theme.
   *
   * @return bool
   *   TRUE if Canvas selected the variant for a theme that is not the default.
   */
  public static function shouldSuppressCanvasChrome(
    string $selected_plugin_id,
    string $canvas_plugin_id,
    string $active_theme,
    string $default_theme,
  ): bool {
    return $selected_plugin_id === $canvas_plugin_id && $active_theme !== $default_theme;
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, array<int, array{0: string, 1: int}>>
   *   The subscribed events.
   */
  public static function getSubscribedEvents(): array {
    $events[RenderEvents::SELECT_PAGE_DISPLAY_VARIANT][] = ['onSelectPageDisplayVariant', -110];
    return $events;
  }

  /**
   * Reads the Canvas page variant plugin ID from its plugin attribute.
   *
   * @return string|null
   *   The plugin ID, or NULL if Canvas does not declare it.
   */
  private static function getCanvasPageVariantPluginId(): ?string {
    $attributes = (new \ReflectionClass(CanvasPageVariant::class))
      ->getAttributes(PageDisplayVariant::class);
    if ($attributes === []) {
      return NULL;
    }
    $attribute = $attributes[0]->newInstance();
    \assert($attribute instanceof PageDisplayVariant);
    return $attribute->getId();
  }

}
