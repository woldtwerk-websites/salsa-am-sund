<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_site\Unit;

use Drupal\workspace_site\EventSubscriber\PageVariantThemeGuardSubscriber;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Canvas page variant theme guard decision.
 */
#[CoversClass(PageVariantThemeGuardSubscriber::class)]
#[Group('workspace_site')]
final class PageVariantThemeGuardSubscriberTest extends UnitTestCase {

  /**
   * Tests the decision to suppress the Canvas page chrome.
   */
  #[DataProvider('decisionProvider')]
  public function testShouldSuppressCanvasChrome(string $selected, string $active_theme, string $default_theme, bool $expected): void {
    self::assertSame(
      $expected,
      PageVariantThemeGuardSubscriber::shouldSuppressCanvasChrome($selected, 'canvas', $active_theme, $default_theme),
    );
  }

  /**
   * Provides plugin IDs, themes and the expected decision.
   *
   * @return array<string, array{string, string, string, bool}>
   *   The selected plugin ID, active theme, default theme and expectation.
   */
  public static function decisionProvider(): array {
    return [
      'Canvas on the admin theme' => ['canvas', 'gin', 'salsa', TRUE],
      'Canvas on the default theme' => ['canvas', 'salsa', 'salsa', FALSE],
      'Canvas on another non-default theme' => ['canvas', 'stark', 'salsa', TRUE],
      'Block page layout' => ['block_page', 'gin', 'salsa', FALSE],
      'Simple page layout' => ['simple_page', 'gin', 'salsa', FALSE],
    ];
  }

}
