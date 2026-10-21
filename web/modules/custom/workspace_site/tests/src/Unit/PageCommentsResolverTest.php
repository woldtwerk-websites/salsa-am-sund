<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_site\Unit;

use Drupal\comment\CommentingStatus;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\workspace_site\PageCommentsResolver;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the page comments resolver.
 *
 * @coversDefaultClass \Drupal\workspace_site\PageCommentsResolver
 */
#[CoversClass(PageCommentsResolver::class)]
#[Group('workspace_site')]
final class PageCommentsResolverTest extends UnitTestCase {

  /**
   * Tests that only an actual Canvas page is resolved.
   */
  public function testResolvePageWithoutCanvasPage(): void {
    $resolver = new PageCommentsResolver();

    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->expects($this->once())
      ->method('getParameter')
      ->with(PageCommentsResolver::PAGE_ROUTE_PARAMETER)
      ->willReturn(NULL);
    self::assertNull($resolver->resolvePage($route_match));

    // A route parameter that is not a Canvas page must be ignored.
    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getParameter')
      ->with(PageCommentsResolver::PAGE_ROUTE_PARAMETER)
      ->willReturn($this->createMock(FieldableEntityInterface::class));
    self::assertNull($resolver->resolvePage($route_match));
  }

  /**
   * Tests the placeholder decision for Canvas routes.
   */
  #[DataProvider('routeNameProvider')]
  public function testShouldDisplayPlaceholder(?string $route_name, bool $expected): void {
    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getRouteName')->willReturn($route_name);

    $resolver = new PageCommentsResolver();
    self::assertSame($expected, $resolver->shouldDisplayPlaceholder($route_match));
  }

  /**
   * Provides route names and the expected placeholder decision.
   *
   * @return array<string, array{string|null, bool}>
   *   The route names and whether the placeholder should be displayed.
   */
  public static function routeNameProvider(): array {
    return [
      'Canvas editor' => ['canvas.boot.entity', TRUE],
      'Canvas API' => ['canvas.api.content.get', TRUE],
      'Canvas page canonical' => ['entity.canvas_page.canonical', FALSE],
      'Front page' => ['<front>', FALSE],
      'No route' => [NULL, FALSE],
    ];
  }

  /**
   * Tests the comment status decision.
   */
  #[DataProvider('commentStatusProvider')]
  public function testShouldDisplayComments(int $status, bool $expected): void {
    $resolver = new PageCommentsResolver();
    self::assertSame($expected, $resolver->shouldDisplayComments($this->pageWithCommentStatus($status)));
  }

  /**
   * Provides comment statuses and whether comments should be displayed.
   *
   * @return array<string, array{int, bool}>
   *   The comment status and whether comments should be displayed.
   */
  public static function commentStatusProvider(): array {
    return [
      'hidden' => [CommentingStatus::Hidden->value, FALSE],
      'closed' => [CommentingStatus::Closed->value, TRUE],
      'open' => [CommentingStatus::Open->value, TRUE],
    ];
  }

  /**
   * Tests a page without a comment field.
   */
  public function testShouldDisplayCommentsWithoutCommentField(): void {
    $page = $this->createMock(FieldableEntityInterface::class);
    $page->method('hasField')
      ->with(PageCommentsResolver::COMMENT_FIELD_NAME)
      ->willReturn(FALSE);

    $resolver = new PageCommentsResolver();
    self::assertFalse($resolver->shouldDisplayComments($page));
  }

  /**
   * Tests a page whose comment field has no value.
   */
  public function testShouldDisplayCommentsWithoutCommentValue(): void {
    $list = $this->createMock(FieldItemListInterface::class);
    $list->method('get')->with(0)->willReturn(NULL);
    $page = $this->createMock(FieldableEntityInterface::class);
    $page->method('hasField')->willReturn(TRUE);
    $page->method('get')
      ->with(PageCommentsResolver::COMMENT_FIELD_NAME)
      ->willReturn($list);

    $resolver = new PageCommentsResolver();
    self::assertFalse($resolver->shouldDisplayComments($page));
  }

  /**
   * Builds a page mock with the given comment status.
   *
   * @param int $status
   *   The comment status.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The mocked page.
   */
  private function pageWithCommentStatus(int $status): FieldableEntityInterface {
    $status_data = $this->createMock(TypedDataInterface::class);
    $status_data->method('getString')->willReturn((string) $status);

    $item = $this->createMock(FieldItemInterface::class);
    $item->method('get')->with('status')->willReturn($status_data);

    $list = $this->createMock(FieldItemListInterface::class);
    $list->method('get')->with(0)->willReturn($item);

    $page = $this->createMock(FieldableEntityInterface::class);
    $page->method('hasField')
      ->with(PageCommentsResolver::COMMENT_FIELD_NAME)
      ->willReturn(TRUE);
    $page->method('get')
      ->with(PageCommentsResolver::COMMENT_FIELD_NAME)
      ->willReturn($list);

    return $page;
  }

}
