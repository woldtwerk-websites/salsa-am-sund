<?php

declare(strict_types=1);

namespace Drupal\workspace_admin_ui\Hook;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\user\UserInterface;

/**
 * Hook implementations for the Admin UI module.
 */
class AdminUiHooks {

  use StringTranslationTrait;

  /**
   * The account behind the User shortcut.
   */
  private const USER_NAME = 'user';

  /**
   * The account behind the Editor shortcut.
   */
  private const EDITOR_NAME = 'editor';

  /**
   * Constructs the hook implementations.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Implements hook_toolbar().
   *
   * The Masquerade tab is one click into the accounts the site is built
   * for, so stepping into a user or an editor is one click from anywhere in
   * the admin rather than a trip to /admin/people. It carries the accounts in
   * its tray rather than as tabs of their own — names with nothing above them
   * would say nothing about what clicking them does.
   *
   * @return array<string, mixed>
   *   The toolbar items.
   */
  #[Hook('toolbar')]
  public function toolbar(): array {
    $item = [
      '#cache' => [
        'contexts' => ['user.permissions', 'session.is_masquerading'],
        'tags' => [],
      ],
    ];

    $links = [];
    $shortcuts = [
      self::USER_NAME => $this->t('User'),
      self::EDITOR_NAME => $this->t('Editor'),
    ];
    foreach ($shortcuts as $name => $title) {
      $account = $this->accountByName($name);
      // The account's own tags once there is one to invalidate on, and the
      // list tag while there is not: the name is the constant here, so the
      // link has to appear when an account is created under it.
      $item['#cache']['tags'] = Cache::mergeTags(
        $item['#cache']['tags'],
        $account?->getCacheTags() ?? ['user_list'],
      );

      if ($account === NULL || !masquerade_target_user_access($account)) {
        continue;
      }

      $links[] = [
        'title' => $title,
        'url' => $account->toUrl('masquerade'),
        'attributes' => [
          'title' => $this->t('Masquerade as @name', ['@name' => $account->getDisplayName()]),
        ],
      ];
    }

    // Nothing to offer: none of the accounts is there, or the current user may
    // not masquerade as any — while already masquerading, most of all, which
    // is what `session.is_masquerading` above varies on.
    return [
      'workspace_admin_ui_masquerade' => $links === [] ? $item : $item + [
        '#type' => 'toolbar_item',
        'tab' => [
          '#type' => 'link',
          '#title' => $this->t('Masquerade'),
          // Where the tab itself goes, and what it falls back to without the
          // JavaScript that opens the tray: masquerade's own form, which can
          // reach every account rather than these two.
          '#url' => Url::fromRoute('masquerade.block'),
          '#attributes' => [
            'title' => $this->t('Masquerade as a user or an editor'),
            'class' => ['toolbar-icon', 'toolbar-icon-masquerade'],
          ],
        ],
        'tray' => [
          '#heading' => $this->t('Masquerade'),
          'links' => [
            '#theme' => 'links__toolbar_masquerade',
            '#links' => $links,
            '#attributes' => ['class' => ['toolbar-menu']],
          ],
        ],
        '#weight' => 110,
        '#attached' => ['library' => ['workspace_admin_ui/toolbar']],
      ],
    ];
  }

  /**
   * Loads the account with a user name.
   *
   * @param string $name
   *   The user name.
   *
   * @return \Drupal\user\UserInterface|null
   *   The account, or NULL when no account has the name.
   */
  private function accountByName(string $name): ?UserInterface {
    $accounts = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['name' => $name]);
    /** @var \Drupal\user\UserInterface|null $account */
    $account = reset($accounts) ?: NULL;

    return $account;
  }

  /**
   * Implements hook_toolbar_alter().
   *
   * @param array<string, mixed> $items
   *   The toolbar items, keyed by item id.
   */
  #[Hook('toolbar_alter')]
  public function toolbarAlter(array &$items): void {
    // The Unmasquerade action lives in the user tray, not in its own tab.
    unset($items['masquerade_switch_back']);
  }

  /**
   * Implements hook_menu_links_discovered_alter().
   *
   * Taxonomy, URL aliases and menus are day-to-day editing, not site
   * building, so they leave Structure and Configuration for Content. Gin's
   * sidebar drops the Content subtree of the admin menu —
   * preprocessNavigation() puts the links back where Gin shows Content.
   *
   * @param array<string, array<string, mixed>> $links
   *   The menu link definitions, keyed by plugin id.
   */
  #[Hook('menu_links_discovered_alter')]
  public function menuLinksDiscoveredAlter(array &$links): void {
    foreach (array_keys(self::contentLinks()) as $id) {
      if (isset($links[$id])) {
        $links[$id]['parent'] = 'system.admin_content';
      }
    }
  }

  /**
   * Implements hook_theme_registry_alter().
   *
   * Gin registers `navigation` with its own list of preprocess functions,
   * which shuts out module preprocess hooks, so the callback is appended to
   * that list directly.
   *
   * @param array<string, mixed> $theme_registry
   *   The theme registry.
   */
  #[Hook('theme_registry_alter')]
  public function themeRegistryAlter(array &$theme_registry): void {
    if (isset($theme_registry['navigation']['preprocess functions'])) {
      $theme_registry['navigation']['preprocess functions'][] = self::class . '::preprocessNavigation';
    }
  }

  /**
   * Preprocess callback for gin's navigation.
   *
   * Gin builds its Content block from a fixed list — Content, Blocks, Files,
   * Media — rather than from the admin menu, so the links moved under Content
   * are appended to that list. Static because the theme registry, which
   * caches it, holds callables by name.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  public static function preprocessNavigation(array &$variables): void {
    if (!isset($variables['menu_middle']['content']['#items'])) {
      return;
    }

    $cacheability = CacheableMetadata::createFromRenderArray($variables);
    foreach (self::contentLinks() as $route_name => [$class, $title]) {
      $url = Url::fromRoute($route_name);
      $access = $url->access(return_as_object: TRUE);
      $cacheability->addCacheableDependency($access);
      if ($access->isAllowed()) {
        $variables['menu_middle']['content']['#items'][$class] = [
          'title' => $title,
          'class' => $class,
          'url' => $url->toString(),
        ];
      }
    }
    $cacheability->applyTo($variables);
    $variables['#attached']['library'][] = 'workspace_admin_ui/toolbar';
  }

  /**
   * The admin links that belong under Content, in the order they appear.
   *
   * @return array<string, array{string, \Drupal\Core\StringTranslation\TranslatableMarkup}>
   *   The link's gin class and title, keyed by route name, which is also the
   *   menu link's plugin id.
   */
  private static function contentLinks(): array {
    return [
      'entity.taxonomy_vocabulary.collection' => ['taxonomy', new TranslatableMarkup('Taxonomy')],
      'entity.path_alias.collection' => ['url-aliases', new TranslatableMarkup('URL aliases')],
      'entity.menu.collection' => ['menus', new TranslatableMarkup('Menus')],
    ];
  }

}
