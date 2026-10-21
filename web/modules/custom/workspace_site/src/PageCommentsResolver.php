<?php

declare(strict_types=1);

namespace Drupal\workspace_site;

use Drupal\canvas\Entity\Page;
use Drupal\comment\CommentingStatus;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Decides whether and where the comments of the current Canvas page render.
 *
 * The page resolution and the "should render" decision live here instead of in
 * the block plugin, so that they can be tested without a full page render.
 *
 * @see \Drupal\workspace_site\Plugin\Block\PageCommentsBlock
 */
final class PageCommentsResolver {

  /**
   * The route parameter that holds the Canvas page.
   *
   * @see \Drupal\canvas\Entity\Page
   */
  public const string PAGE_ROUTE_PARAMETER = Page::ENTITY_TYPE_ID;

  /**
   * The name of the comment field on Canvas pages.
   */
  public const string COMMENT_FIELD_NAME = 'comment';

  /**
   * Returns the Canvas page of the given route match, if there is one.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match to inspect.
   *
   * @return \Drupal\canvas\Entity\Page|null
   *   The page, or NULL if the route does not contain a Canvas page.
   */
  public function resolvePage(RouteMatchInterface $route_match): ?Page {
    $page = $route_match->getParameter(self::PAGE_ROUTE_PARAMETER);
    return $page instanceof Page ? $page : NULL;
  }

  /**
   * Determines whether the comments of the given page should be rendered.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $page
   *   The page to inspect.
   *
   * @return bool
   *   TRUE if the page has a comment field that is not hidden.
   */
  public function shouldDisplayComments(FieldableEntityInterface $page): bool {
    if (!$page->hasField(self::COMMENT_FIELD_NAME)) {
      return FALSE;
    }
    // CommentFieldItemList::get() falls back to the field's default value, so
    // pages that were created before the field was added resolve to HIDDEN
    // unless the default was changed.
    $item = $page->get(self::COMMENT_FIELD_NAME)->get(0);
    if ($item === NULL) {
      return FALSE;
    }
    return (int) $item->get('status')->getString() !== CommentingStatus::Hidden->value;
  }

  /**
   * Determines whether the editor placeholder should be rendered.
   *
   * Canvas routes (for example the editor route) do not have a page entity in
   * their route parameters, so the block renders a placeholder there.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match to inspect.
   *
   * @return bool
   *   TRUE if the current route is a Canvas route.
   */
  public function shouldDisplayPlaceholder(RouteMatchInterface $route_match): bool {
    $route_name = $route_match->getRouteName();
    return $route_name !== NULL && str_starts_with($route_name, 'canvas.');
  }

}
