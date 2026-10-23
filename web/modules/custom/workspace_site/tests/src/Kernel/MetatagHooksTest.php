<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_site\Kernel;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\workspace_site\Hook\MetatagHooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the meta tag alterations of this module.
 */
#[CoversClass(MetatagHooks::class)]
#[Group('workspace_site')]
#[RunTestsInSeparateProcesses]
final class MetatagHooksTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'contact',
    'comment',
    'workspace_site',
  ];

  /**
   * The hooks under test.
   */
  private MetatagHooks $hooks;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');

    $config_factory = $this->container->get('config.factory');
    \assert($config_factory instanceof ConfigFactoryInterface);
    $this->hooks = new MetatagHooks($config_factory);
  }

  /**
   * Tests that the front page shares the canonical URL with its alias.
   */
  public function testFrontPageUsesSiteUrl(): void {
    $page = Page::create([
      'title' => 'Start page',
      'path' => ['alias' => '/startseite'],
    ]);
    $page->save();
    $this->config('system.site')->set('page.front', '/startseite')->save();

    self::assertSame('[site:url]', $this->alterCanonicalUrl($page));
  }

  /**
   * Tests that other Canvas pages get an absolute canonical URL.
   */
  public function testOtherPageUsesAbsoluteUrl(): void {
    $page = Page::create([
      'title' => 'Another page',
      'path' => ['alias' => '/another-page'],
    ]);
    $page->save();

    self::assertSame('[canvas_page:url:absolute]', $this->alterCanonicalUrl($page));
  }

  /**
   * Tests that an editor-defined canonical URL is kept.
   */
  public function testCustomCanonicalUrlIsUnchanged(): void {
    $page = Page::create([
      'title' => 'Custom canonical page',
      'path' => ['alias' => '/custom-canonical'],
    ]);
    $page->save();

    $metatags = ['canonical_url' => 'https://example.com/custom'];
    $context = ['entity' => $page];
    $this->hooks->alterMetatags($metatags, $context);

    self::assertSame('https://example.com/custom', $metatags['canonical_url']);
  }

  /**
   * Tests that meta tags of non-Canvas entities are not changed.
   */
  public function testNonCanvasEntityIsUnchanged(): void {
    $entity = $this->createMock(ContentEntityInterface::class);

    $metatags = ['canonical_url' => '[canvas_page:url]'];
    $context = ['entity' => $entity];
    $this->hooks->alterMetatags($metatags, $context);

    self::assertSame('[canvas_page:url]', $metatags['canonical_url']);
  }

  /**
   * Alters the canonical URL of a page and returns the result.
   *
   * @param \Drupal\canvas\Entity\Page $page
   *   The page.
   *
   * @return string
   *   The altered canonical URL.
   */
  private function alterCanonicalUrl(Page $page): string {
    $metatags = ['canonical_url' => '[canvas_page:url]'];
    $context = ['entity' => $page];
    $this->hooks->alterMetatags($metatags, $context);
    return $metatags['canonical_url'];
  }

}
