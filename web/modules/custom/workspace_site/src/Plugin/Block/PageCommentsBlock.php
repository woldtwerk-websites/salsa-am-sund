<?php

declare(strict_types=1);

namespace Drupal\workspace_site\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\workspace_site\PageCommentsResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a block that renders the comments of the current Canvas page.
 *
 * @see \Drupal\workspace_site\PageCommentsResolver
 */
#[Block(
  id: 'salsa_page_comments',
  admin_label: new TranslatableMarkup('Page comments'),
  category: new TranslatableMarkup('Salsa am Sund'),
)]
final class PageCommentsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a PageCommentsBlock object.
   *
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param array<string, mixed> $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   * @param \Drupal\workspace_site\PageCommentsResolver $resolver
   *   The page comments resolver.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    protected readonly RouteMatchInterface $routeMatch,
    protected readonly PageCommentsResolver $resolver,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param array<string, mixed> $plugin_definition
   *   The plugin implementation definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $route_match = $container->get(RouteMatchInterface::class);
    \assert($route_match instanceof RouteMatchInterface);
    $resolver = $container->get(PageCommentsResolver::class);
    \assert($resolver instanceof PageCommentsResolver);

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $route_match,
      $resolver,
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<mixed>
   *   The render array of the comments.
   */
  public function build(): array {
    $build = [];
    $page = $this->resolver->resolvePage($this->routeMatch);
    if ($page === NULL) {
      // The Canvas editor preview has no page entity in its route, so show a
      // placeholder instead of nothing at all.
      if ($this->resolver->shouldDisplayPlaceholder($this->routeMatch)) {
        $build['#markup'] = $this->t('Comments and the comment form of this page appear here.');
      }
    }
    elseif ($this->resolver->shouldDisplayComments($page)) {
      $build = $page->get(PageCommentsResolver::COMMENT_FIELD_NAME)->view([
        'label' => 'hidden',
        'type' => 'comment_default',
        'settings' => [
          'view_mode' => 'default',
          'pager_id' => 0,
        ],
      ]);
    }

    // The output depends on the current route in every case.
    $cacheability = CacheableMetadata::createFromRenderArray($build);
    $cacheability->addCacheContexts(['route']);
    if ($page !== NULL) {
      $cacheability->addCacheableDependency($page);
    }
    $cacheability->applyTo($build);

    return $build;
  }

}
