<?php

declare(strict_types=1);

namespace Drupal\salsa\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Drupal\workspace_gallery\GalleryImages;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Hook implementations for the Salsa am Sund theme.
 */
final class SalsaHooks {

  use StringTranslationTrait;

  /**
   * Constructs the hook class.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container, used to load optional module services lazily.
   */
  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly LanguageManagerInterface $languageManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly TimeInterface $time,
    private readonly ContainerInterface $container,
  ) {}

  /**
   * Implements hook_preprocess_html().
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   */
  #[Hook('preprocess_html')]
  public function preprocessHtml(array &$variables): void {
    if (isset($variables['html_attributes']) && $variables['html_attributes'] instanceof Attribute) {
      $variables['html_attributes']->addClass('salsa-theme');
    }
  }

  /**
   * Implements hook_preprocess_node().
   *
   * @param array<mixed> $variables
   *   The theme variables.
   *
   * @param-out array<mixed> $variables
   */
  #[Hook('preprocess_node')]
  public function preprocessNode(array &$variables): void {
    $node = $variables['node'] ?? NULL;
    if (!$node instanceof NodeInterface || $node->bundle() !== 'gallery') {
      return;
    }
    $view_mode = $variables['view_mode'] ?? NULL;
    if (!is_string($view_mode) || !in_array($view_mode, ['full', 'card'], TRUE)) {
      return;
    }
    if (!$this->container->has(GalleryImages::class)) {
      return;
    }

    // A card only ever shows the first image, so avoid loading the entire
    // gallery for every teaser in the overview.
    $gallery_images = $this->container->get(GalleryImages::class);
    if (!$gallery_images instanceof GalleryImages) {
      return;
    }
    $result = $gallery_images->build($node, $view_mode === 'card' ? 1 : NULL);
    $variables['gallery_images'] = $result['images'];

    $count = $node->hasField('field_gallery_images') ? $node->get('field_gallery_images')->count() : 0;
    $variables['gallery_image_count'] = $count;
    $variables['gallery_image_count_label'] = $this->formatPlural($count, '@count Bild', '@count Bilder');

    $cache = CacheableMetadata::createFromRenderArray($variables);
    $cache->merge($result['cache']);
    $cache->applyTo($variables);
  }

  /**
   * Implements hook_preprocess_page_title().
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   */
  #[Hook('preprocess_page_title')]
  public function preprocessPageTitle(array &$variables): void {
    $cache = $variables['#cache'] ?? [];
    $cache = is_array($cache) ? $cache : [];
    $contexts = $cache['contexts'] ?? [];
    $contexts = is_array($contexts) ? $contexts : [];
    $contexts[] = 'route';
    $cache['contexts'] = $contexts;
    $variables['#cache'] = $cache;

    // Canvas pages carry their own <h1> in the hero component, and profile
    // pages carry it in the profile component.
    $route_name = $this->routeMatch->getRouteName();
    if (in_array($route_name, ['entity.canvas_page.canonical', 'entity.user.canonical'], TRUE)) {
      $variables['title'] = '';
    }
  }

  /**
   * Implements hook_theme_suggestions_user_alter().
   *
   * @param array<int, string> $suggestions
   *   The theme suggestions.
   * @param array<string, mixed> $variables
   *   The theme variables.
   */
  #[Hook('theme_suggestions_user_alter')]
  public function themeSuggestionsUserAlter(array &$suggestions, array $variables): void {
    $elements = $variables['elements'] ?? NULL;
    $view_mode = $variables['view_mode'] ?? (is_array($elements) ? ($elements['#view_mode'] ?? NULL) : NULL);
    if ($view_mode === 'compact') {
      $suggestions[] = 'user__compact';
    }
  }

  /**
   * Implements hook_preprocess_user().
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   */
  #[Hook('preprocess_user')]
  public function preprocessUser(array &$variables): void {
    $user = $variables['user'] ?? NULL;
    if (!$user instanceof UserInterface) {
      $elements = $variables['elements'] ?? NULL;
      $user = is_array($elements) ? ($elements['#user'] ?? NULL) : NULL;
    }
    if (!$user instanceof UserInterface) {
      return;
    }

    $name = (string) $user->getDisplayName();
    $words = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';
    foreach ($words as $word) {
      if ($word !== '') {
        $initials .= mb_substr($word, 0, 1);
      }
    }
    $variables['salsa_initials'] = mb_strtoupper(mb_substr($initials, 0, 2));

    $picture = [];
    if ($user->hasField('user_picture') && !$user->get('user_picture')->isEmpty()) {
      $file = $user->get('user_picture')->entity;
      if ($file instanceof FileInterface) {
        $uri = $file->getFileUri();
        $width = $file->get('width')->value ?? 0;
        $height = $file->get('height')->value ?? 0;
        if ($uri !== NULL) {
          $picture = [
            'src' => $this->fileUrlGenerator->generateString($uri),
            'alt' => $name,
            'width' => is_numeric($width) ? (int) $width : 0,
            'height' => is_numeric($height) ? (int) $height : 0,
          ];
        }
      }
    }
    $variables['salsa_profile_picture'] = $picture;

    $created = (int) $user->getCreatedTime();
    if ($created > 0) {
      $date = $this->dateFormatter->format($created, 'custom', 'd.m.Y');
      $interval = $this->dateFormatter->formatInterval(
        max(0, $this->time->getRequestTime() - $created),
        2,
      );
      $variables['salsa_member_since'] = $this->t('Mitglied seit @date (vor @interval)', [
        '@date' => $date,
        '@interval' => $interval,
      ]);
    }
    else {
      $variables['salsa_member_since'] = '';
    }

    // The "vor ..." interval changes over time even when the profile is cached.
    $cache = $variables['#cache'] ?? [];
    $cache = is_array($cache) ? $cache : [];
    $cache['max-age'] = 3600;
    $variables['#cache'] = $cache;
  }

  /**
   * Implements hook_preprocess_canvas_page_variant().
   *
   * Canvas replaces the marker with the route's main content. For routes that
   * are not Canvas pages or Canvas content templates, that content has no
   * layout container of its own (for example contact forms, user profiles and
   * 403/404 pages). Wrap the marker output in the same container as the page
   * title. Canvas pages and content-template renderings stay full-bleed.
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   */
  #[Hook('preprocess_canvas_page_variant')]
  public function preprocessCanvasPageVariant(array &$variables): void {
    $this->addRouteCacheContext($variables);

    $content = &$variables['content'];
    if (!is_array($content)) {
      return;
    }

    // Always use the default-language front page; the current interface
    // language may add a prefix such as /en that the logo must not inherit.
    $front_url = Url::fromRoute('<front>', [], [
      'language' => $this->languageManager->getDefaultLanguage(),
    ])->toString();
    $this->addConfigCacheTag($variables);
    $this->injectComponentProp($content, 'salsa:site-header', 'front_url', $front_url);

    if ($this->isCanvasRenderedRoute()) {
      return;
    }

    $this->wrapMarkerContent($content);
  }

  /**
   * Implements hook_form_comment_form_alter().
   *
   * The theme prints comment forms without the text format guidelines and the
   * "About text formats" link, so strip them once the text format element has
   * been processed.
   *
   * @param array<mixed> $form
   *   The comment form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   *
   * @param-out array<mixed> $form
   */
  #[Hook('form_comment_form_alter')]
  public function formCommentFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    self::addTextFormatHelperCleanup($form);
  }

  /**
   * Adds the guideline/help cleanup callback to all text format elements.
   *
   * Comment body fields nest the text format element inside a field widget
   * container, so the form tree is searched recursively.
   *
   * @param array<mixed> $element
   *   The form or form element, passed by reference.
   *
   * @param-out array<mixed> $element
   */
  private static function addTextFormatHelperCleanup(array &$element): void {
    if (($element['#type'] ?? NULL) === 'text_format') {
      $after_build = $element['#after_build'] ?? [];
      $after_build = is_array($after_build) ? $after_build : [];
      $after_build[] = [self::class, 'stripTextFormatHelpers'];
      $element['#after_build'] = $after_build;
      return;
    }

    foreach (Element::children($element) as $key) {
      if (!is_string($key) && !is_int($key)) {
        continue;
      }
      $child = $element[$key] ?? NULL;
      if (!is_array($child)) {
        continue;
      }
      self::addTextFormatHelperCleanup($child);
      $element[$key] = $child;
    }
  }

  /**
   * After-build callback: removes the text format guidelines and help.
   *
   * When only one text format is available, core hides the format selector but
   * still prints the wrapper around the guidelines. Hide that wrapper as well;
   * its children keep their #value, so the text format is still submitted.
   *
   * @param array<mixed> $element
   *   The text format element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<mixed>
   *   The altered text format element.
   */
  public static function stripTextFormatHelpers(array $element, FormStateInterface $form_state): array {
    $format = $element['format'] ?? NULL;
    if (!is_array($format)) {
      return $element;
    }

    unset($format['guidelines'], $format['help']);

    $selector = $format['format'] ?? NULL;
    if (is_array($selector) && empty($selector['#access'])) {
      $format['#access'] = FALSE;
    }

    $element['format'] = $format;
    return $element;
  }

  /**
   * Adds the site configuration cache tag to the render variables.
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   */
  private function addConfigCacheTag(array &$variables): void {
    $cache = $variables['#cache'] ?? [];
    $cache = is_array($cache) ? $cache : [];
    $tags = $cache['tags'] ?? [];
    $tags = is_array($tags) ? $tags : [];
    $tags[] = 'config:system.site';
    $cache['tags'] = $tags;
    $variables['#cache'] = $cache;
  }

  /**
   * Injects a prop into every matching component in a render tree.
   *
   * @param array<mixed> $content
   *   The render tree.
   * @param string $component_id
   *   The SDC component ID.
   * @param string $prop
   *   The prop name.
   * @param string $value
   *   The prop value.
   */
  private function injectComponentProp(array &$content, string $component_id, string $prop, string $value): bool {
    foreach ($content as &$item) {
      if (!is_array($item)) {
        continue;
      }
      $component = $item['#component'] ?? NULL;
      if (is_array($component) && ($component['#component'] ?? NULL) === $component_id) {
        $props = $component['#props'] ?? NULL;
        if (is_array($props)) {
          $props[$prop] = $value;
          $component['#props'] = $props;
          $item['#component'] = $component;
          unset($item);
          return TRUE;
        }
      }
      if ($this->injectComponentProp($item, $component_id, $prop, $value)) {
        unset($item);
        return TRUE;
      }
    }
    unset($item);
    return FALSE;
  }

  /**
   * Adds the route cache context to a render array's variables.
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   */
  private function addRouteCacheContext(array &$variables): void {
    $cache = $variables['#cache'] ?? [];
    $cache = is_array($cache) ? $cache : [];
    $contexts = $cache['contexts'] ?? [];
    $contexts = is_array($contexts) ? $contexts : [];
    $contexts[] = 'route';
    $cache['contexts'] = $contexts;
    $variables['#cache'] = $cache;
  }

  /**
   * Determines whether the current route is rendered by Canvas itself.
   */
  private function isCanvasRenderedRoute(): bool {
    if ($this->routeMatch->getRouteName() === 'entity.canvas_page.canonical') {
      return TRUE;
    }

    if (!class_exists(ContentTemplate::class)) {
      return FALSE;
    }

    foreach ($this->routeMatch->getParameters() as $parameter) {
      if (!$parameter instanceof FieldableEntityInterface) {
        continue;
      }
      $template = ContentTemplate::loadForEntity($parameter, 'full');
      if ($template !== NULL && $template->status()) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Wraps the Canvas "Page content" marker render array in a layout container.
   *
   * @param array<mixed> $content
   *   The page variant render array, passed by reference.
   */
  private function wrapMarkerContent(array &$content): bool {
    foreach ($content as &$item) {
      if (!is_array($item)) {
        continue;
      }

      $component = $item['#component'] ?? NULL;
      if (is_array($component) && ($component['#component'] ?? NULL) === 'salsa:page-main') {
        $slots = $component['#slots'] ?? NULL;
        if (!is_array($slots) || !isset($slots['content']) || !is_array($slots['content'])) {
          continue;
        }
        foreach ($slots['content'] as &$slot_item) {
          if (!is_array($slot_item)) {
            continue;
          }
          $slot_component = $slot_item['#component'] ?? NULL;
          // The marker is the only page-main slot item that renders plain
          // markup instead of a block component.
          if (is_array($slot_component) && isset($slot_component['#markup'])) {
            $slot_item = [
              '#type' => 'container',
              '#attributes' => ['class' => ['salsa-route-content']],
              'content' => $slot_item,
            ];
            unset($slot_item);
            $component['#slots'] = $slots;
            $item['#component'] = $component;
            return TRUE;
          }
        }
        unset($slot_item);
      }

      if ($this->wrapMarkerContent($item)) {
        return TRUE;
      }
    }
    unset($item);

    return FALSE;
  }

}
