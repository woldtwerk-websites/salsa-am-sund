<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_iconify_extractor\Unit;

use Drupal\Core\Theme\Icon\IconDefinitionInterface;
use Drupal\Core\Theme\Icon\IconFinder;
use Drupal\Tests\UnitTestCase;
use Drupal\workspace_iconify_extractor\Plugin\IconExtractor\IconfiyExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Iconify icon extractor.
 */
#[CoversClass(IconfiyExtractor::class)]
#[Group('workspace_iconify_extractor')]
final class IconfiyExtractorTest extends UnitTestCase {

  /**
   * The temporary directory holding the Iconify JSON fixtures.
   */
  private string $tempDirectory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->resetIconSetCache();
    $this->tempDirectory = sys_get_temp_dir() . '/iconify_extractor_' . uniqid('', TRUE);
    mkdir($this->tempDirectory, 0777, TRUE);

    $this->writeIconSet('icons.json', [
      'prefix' => 'test',
      'width' => 32,
      'height' => 32,
      'icons' => [
        'circle' => ['body' => '<circle cx="16" cy="16" r="8"/>'],
        'wide' => [
          'body' => '<rect width="20" height="10"/>',
          'width' => 20,
          'height' => 10,
        ],
      ],
      'aliases' => [
        'alias-intermediate' => ['parent' => 'alias-final'],
        'alias-final' => ['parent' => 'circle'],
        'alias-missing' => ['parent' => 'does-not-exist'],
      ],
    ]);

    $this->writeIconSet('no-root.json', [
      'prefix' => 'test',
      'icons' => [
        'plain' => ['body' => '<path d="M0 0"/>'],
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach (glob($this->tempDirectory . '/*') ?: [] as $file) {
      unlink($file);
    }
    if (is_dir($this->tempDirectory)) {
      rmdir($this->tempDirectory);
    }
    $this->resetIconSetCache();

    parent::tearDown();
  }

  /**
   * Tests that alias chains resolve to the final icon.
   */
  public function testDiscoverIconsResolvesAliasChains(): void {
    $icons = $this->createExtractor()->discoverIcons();

    self::assertSame([
      'test_pack:circle',
      'test_pack:wide',
      'test_pack:alias-intermediate',
      'test_pack:alias-final',
    ], array_keys($icons));
    self::assertSame('circle', $icons['test_pack:alias-intermediate']['normalized_icon_id']);
    self::assertSame('circle', $icons['test_pack:alias-final']['normalized_icon_id']);
    self::assertArrayNotHasKey('test_pack:alias-missing', $icons);
    self::assertSame([
      'absolute_path' => $this->tempDirectory,
      'file' => 'icons.json',
      'normalized_icon_id' => 'circle',
    ], $icons['test_pack:circle']);
  }

  /**
   * Tests a set without any aliases.
   */
  public function testDiscoverIconsWithoutAliases(): void {
    $extractor = $this->createExtractor(['config' => ['sources' => ['no-root.json']]]);

    self::assertSame(['test_pack:plain'], array_keys($extractor->discoverIcons()));
  }

  /**
   * Tests that per-icon dimensions take precedence over the set dimensions.
   */
  public function testLoadIconUsesOwnDimensions(): void {
    $icon = $this->loadDiscoveredIcon('icons.json', 'wide');

    self::assertInstanceOf(IconDefinitionInterface::class, $icon);
    $this->assertSvgDimensions($icon, '0 0 20 10', '20', '10');
  }

  /**
   * Tests that icon dimensions fall back to the set dimensions.
   */
  public function testLoadIconFallsBackToSetDimensions(): void {
    $icon = $this->loadDiscoveredIcon('icons.json', 'circle');

    self::assertInstanceOf(IconDefinitionInterface::class, $icon);
    $this->assertSvgDimensions($icon, '0 0 32 32', '32', '32');
  }

  /**
   * Tests that icon dimensions fall back to the Iconify default.
   */
  public function testLoadIconFallsBackToDefaultSize(): void {
    $icon = $this->loadDiscoveredIcon('no-root.json', 'plain');

    self::assertInstanceOf(IconDefinitionInterface::class, $icon);
    $this->assertSvgDimensions($icon, '0 0 16 16', '16', '16');
  }

  /**
   * Tests that a missing icon is not loaded.
   */
  public function testLoadIconReturnsNullForMissingIcon(): void {
    $extractor = $this->createExtractor();

    self::assertNull($extractor->loadIcon($this->iconData('icons.json', 'missing')));
  }

  /**
   * Tests that the icon set file is only read once across plugin instances.
   */
  public function testIconSetIsReadOnceAcrossInstances(): void {
    $icon = $this->createExtractor()->loadIcon($this->iconData('icons.json', 'circle'));
    self::assertInstanceOf(IconDefinitionInterface::class, $icon);

    // IconCollector creates a new extractor for every icon cache miss. The
    // second instance must be served from the decoded set cache.
    unlink($this->tempDirectory . '/icons.json');

    $icon = $this->createExtractor()->loadIcon($this->iconData('icons.json', 'wide'));
    self::assertInstanceOf(IconDefinitionInterface::class, $icon);
    $this->assertSvgDimensions($icon, '0 0 20 10', '20', '10');
  }

  /**
   * Creates an extractor plugin with the test fixtures.
   *
   * @param array<string, mixed> $configuration
   *   Configuration overriding the defaults.
   *
   * @return \Drupal\workspace_iconify_extractor\Plugin\IconExtractor\IconfiyExtractor
   *   The extractor under test.
   */
  private function createExtractor(array $configuration = []): IconfiyExtractor {
    $configuration += [
      'id' => 'test_pack',
      'absolute_path' => $this->tempDirectory,
      'config' => ['sources' => ['icons.json']],
      'template' => '<svg>{{ content }}</svg>',
    ];

    return new IconfiyExtractor(
      $configuration,
      'iconfiy',
      ['id' => 'iconfiy'],
      $this->createMock(IconFinder::class),
    );
  }

  /**
   * Loads a single icon through discovery, as the icon collector does.
   *
   * @param string $file
   *   The icon set file.
   * @param string $icon_id
   *   The icon id within the set.
   *
   * @return \Drupal\Core\Theme\Icon\IconDefinitionInterface|null
   *   The loaded icon.
   */
  private function loadDiscoveredIcon(string $file, string $icon_id): ?IconDefinitionInterface {
    $extractor = $this->createExtractor(['config' => ['sources' => [$file]]]);
    $icon_data = $extractor->discoverIcons()['test_pack:' . $icon_id] ?? [];
    $icon_data['icon_id'] = $icon_id;

    return $extractor->loadIcon($icon_data);
  }

  /**
   * Builds icon data for an icon that was not discovered.
   *
   * @param string $file
   *   The icon set file.
   * @param string $icon_id
   *   The icon id within the set.
   *
   * @return array<string, string>
   *   The icon data.
   */
  private function iconData(string $file, string $icon_id): array {
    return [
      'absolute_path' => $this->tempDirectory,
      'file' => $file,
      'normalized_icon_id' => $icon_id,
      'icon_id' => $icon_id,
    ];
  }

  /**
   * Asserts the dimensions rendered into the encoded SVG source.
   *
   * @param \Drupal\Core\Theme\Icon\IconDefinitionInterface $icon
   *   The icon.
   * @param string $view_box
   *   The expected viewBox value.
   * @param string $width
   *   The expected width value.
   * @param string $height
   *   The expected height value.
   */
  private function assertSvgDimensions(IconDefinitionInterface $icon, string $view_box, string $width, string $height): void {
    $source = $icon->getSource();
    self::assertIsString($source);

    $prefix = 'data:image/svg+xml;base64,';
    self::assertStringStartsWith($prefix, $source);

    $svg = base64_decode(substr($source, strlen($prefix)), TRUE);
    self::assertIsString($svg);
    self::assertStringContainsString('viewBox="' . $view_box . '"', $svg);
    self::assertStringContainsString('width="' . $width . '"', $svg);
    self::assertStringContainsString('height="' . $height . '"', $svg);
  }

  /**
   * Resets the process-wide icon set cache.
   */
  private function resetIconSetCache(): void {
    $reflection = new \ReflectionClass(IconfiyExtractor::class);
    $reflection->setStaticPropertyValue('iconSets', []);
  }

  /**
   * Writes an Iconify JSON icon set fixture.
   *
   * @param string $file
   *   The file name.
   * @param array<string, mixed> $icon_set
   *   The icon set data.
   */
  private function writeIconSet(string $file, array $icon_set): void {
    file_put_contents(
      $this->tempDirectory . '/' . $file,
      json_encode($icon_set, JSON_THROW_ON_ERROR),
    );
  }

}
