<?php

declare(strict_types=1);

namespace Drupal\workspace_iconify_extractor\Plugin\IconExtractor;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Theme\Icon\Attribute\IconExtractor;
use Drupal\Core\Theme\Icon\IconDefinition;
use Drupal\Core\Theme\Icon\IconDefinitionInterface;
use Drupal\Core\Theme\Icon\IconExtractorWithFinder;
use Drupal\Core\Theme\Icon\IconPackExtractorForm;

/**
 * Extracts icons from Iconify JSON icon sets.
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass (Core offers no public base class for icon extractors.)
 */
#[IconExtractor(
  id: 'iconfiy',
  label: new TranslatableMarkup('Iconfiy'),
  description: new TranslatableMarkup('Uses @iconify-json package.'),
  forms: [
    'settings' => IconPackExtractorForm::class,
  ]
)]
class IconfiyExtractor extends IconExtractorWithFinder {

  /**
   * Iconify default icon size, used when a set defines no dimensions.
   */
  private const DEFAULT_SIZE = 16;

  /**
   * Maximum number of alias hops before a chain is considered broken.
   */
  private const MAX_ALIAS_DEPTH = 10;

  /**
   * Decoded icon sets, keyed by their absolute file path.
   *
   * Static so the cache survives the short-lived extractor instances created
   * for every icon cache miss by IconCollector.
   *
   * @var array<string, array<mixed>>
   */
  private static array $iconSets = [];

  /**
   * {@inheritdoc}
   *
   * @return array<string, array{absolute_path: string, file: string, normalized_icon_id: string}>
   *   The discovered icons keyed by their full icon id.
   */
  public function discoverIcons(): array {
    $config = $this->getConfigurationValue('config');
    $sources = is_array($config) ? ($config['sources'] ?? NULL) : NULL;
    if (!is_array($sources) || $sources === [] || !array_is_list($sources)) {
      return [];
    }
    foreach ($sources as $source) {
      if (!is_string($source)) {
        return [];
      }
    }

    $absolute_path = $this->getAbsolutePath();
    $pack_id = $this->getPackId();
    if ($absolute_path === NULL || $pack_id === '') {
      return [];
    }

    $icons = [];
    foreach ($sources as $file) {
      $set = $this->loadIconSet($file);

      $set_icons = $set['icons'] ?? [];
      $set_icons = is_array($set_icons) ? $set_icons : [];
      foreach ($set_icons as $id => $icon) {
        if (!is_array($icon)) {
          continue;
        }
        $normalized_id = strval($id);
        $icon_id = IconDefinition::createIconId($pack_id, $normalized_id);
        $icons[$icon_id] = [
          'absolute_path' => $absolute_path,
          'file' => $file,
          'normalized_icon_id' => $normalized_id,
        ];
      }

      $aliases = $set['aliases'] ?? [];
      $aliases = is_array($aliases) ? $aliases : [];
      foreach ($aliases as $id => $alias) {
        if (!is_array($alias)) {
          continue;
        }
        $normalized_id = $this->resolveAlias($aliases, strval($id), $set_icons);
        if ($normalized_id === NULL) {
          continue;
        }
        $icon_id = IconDefinition::createIconId($pack_id, strval($id));
        $icons[$icon_id] = [
          'absolute_path' => $absolute_path,
          'file' => $file,
          'normalized_icon_id' => $normalized_id,
        ];
      }
    }

    return $icons;
  }

  /**
   * Encode svg data to base64 data uri.
   *
   * @param string $svg
   *   The svg content.
   * @param array{width?: int|float, height?: int|float} $meta
   *   The svg meta information.
   *
   * @return string
   *   The encoded svg data uri.
   */
  public function encodeSvg(string $svg, array $meta): string {
    $attributes = new Attribute();
    if (isset($meta['height'], $meta['width'])) {
      $width = (string) $meta['width'];
      $height = (string) $meta['height'];
      $attributes->setAttribute('viewBox', '0 0 ' . $width . ' ' . $height);
      $attributes->setAttribute('height', $height);
      $attributes->setAttribute('width', $width);
    }
    return 'data:image/svg+xml;base64,'
      . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" '
      . $attributes->__toString()
      . '>' . $svg . '</svg>');
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $icon_data
   *   The icon data built by discoverIcons().
   */
  public function loadIcon(array $icon_data): ?IconDefinitionInterface {
    $icon_id = $icon_data['icon_id'] ?? NULL;
    $file = $icon_data['file'] ?? NULL;
    $normalized_id = $icon_data['normalized_icon_id'] ?? NULL;
    if (!is_string($icon_id) || $icon_id === '' || !is_string($file) || !is_string($normalized_id)) {
      return NULL;
    }

    $set = $this->loadIconSet($file);
    $set_icons = $set['icons'] ?? [];
    $icon = is_array($set_icons) ? ($set_icons[$normalized_id] ?? NULL) : NULL;
    if (!is_array($icon)) {
      return NULL;
    }

    $body = $icon['body'] ?? NULL;
    if (!is_string($body) || $body === '') {
      return NULL;
    }

    $meta = [
      'height' => $this->getDimension($icon, $set, 'height'),
      'width' => $this->getDimension($icon, $set, 'width'),
    ];

    if (!$svg_data = $this->extractSvg($body, $meta)) {
      return NULL;
    }

    $group = $icon_data['group'] ?? NULL;

    return $this->createIcon(
      $icon_id,
      $this->encodeSvg($body, $meta),
      is_string($group) ? $group : NULL,
      $svg_data,
    );
  }

  /**
   * Extract svg values, simply exclude parent <svg>.
   *
   * @param string $body
   *   The SVG content without the parent <svg> element.
   * @param array{width?: int|float, height?: int|float} $meta
   *   The svg meta information.
   *
   * @return array{content: \Drupal\Component\Render\FormattableMarkup, attributes: \Drupal\Core\Template\Attribute}|null
   *   The SVG `content` and its `attributes`, or NULL when the body is empty.
   */
  private function extractSvg(string $body, array $meta): ?array {
    $attributes = new Attribute();
    if (isset($meta['height'], $meta['width'])) {
      $width = (string) $meta['width'];
      $height = (string) $meta['height'];
      $attributes->setAttribute('viewBox', '0 0 ' . $width . ' ' . $height);
    }

    if ($body === '') {
      return NULL;
    }

    return [
      'content' => new FormattableMarkup($body, []),
      'attributes' => $attributes,
    ];
  }

  /**
   * Reads and decodes an icon set file.
   *
   * Decoded sets are cached for the lifetime of the PHP process, so a single
   * icon set is only read and decoded once, even across plugin instances.
   *
   * @param string $file
   *   The icon set file path, relative to the icon pack directory.
   *
   * @return array<mixed>
   *   The decoded icon set.
   *
   * @throws \RuntimeException
   *   When the icon set file cannot be read or decoded.
   */
  private function loadIconSet(string $file): array {
    $absolute_path = $this->getAbsolutePath();
    if ($absolute_path === NULL) {
      throw new \RuntimeException(sprintf('Invalid absolute path for icon set: %s', $file));
    }
    $path = $absolute_path . '/' . $file;

    if (isset(self::$iconSets[$path])) {
      return self::$iconSets[$path];
    }

    $content = file_get_contents($path);
    if ($content === FALSE) {
      throw new \RuntimeException(sprintf('Unable to read icon set file: %s', $path));
    }

    try {
      $decoded = json_decode($content, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new \RuntimeException(sprintf('Invalid JSON in icon set file: %s', $path), 0, $e);
    }

    if (!is_array($decoded)) {
      throw new \RuntimeException(sprintf('Icon set file does not contain a JSON object: %s', $path));
    }

    return self::$iconSets[$path] = $decoded;
  }

  /**
   * Resolves an alias to the id of the icon it ultimately points to.
   *
   * Aliases may point to other aliases, so the chain is followed until an
   * icon is found.
   *
   * @param array<mixed> $aliases
   *   The aliases of the icon set, keyed by alias id.
   * @param string $id
   *   The alias id to resolve.
   * @param array<mixed> $icons
   *   The icons of the icon set, keyed by icon id.
   *
   * @return string|null
   *   The resolved icon id, or NULL when the chain is broken or cyclic.
   */
  private function resolveAlias(array $aliases, string $id, array $icons): ?string {
    $seen = [$id => TRUE];
    $depth = 0;
    $parent = $this->getAliasParent($aliases, $id);

    while ($parent !== NULL) {
      if (isset($icons[$parent]) && is_array($icons[$parent])) {
        return $parent;
      }
      if (isset($seen[$parent]) || ++$depth > self::MAX_ALIAS_DEPTH) {
        return NULL;
      }
      $seen[$parent] = TRUE;
      $parent = $this->getAliasParent($aliases, $parent);
    }

    return NULL;
  }

  /**
   * Returns the parent of an alias.
   *
   * @param array<mixed> $aliases
   *   The aliases of the icon set, keyed by alias id.
   * @param string $id
   *   The alias id.
   *
   * @return string|null
   *   The parent id, or NULL when the alias has no string parent.
   */
  private function getAliasParent(array $aliases, string $id): ?string {
    $alias = $aliases[$id] ?? NULL;
    if (!is_array($alias)) {
      return NULL;
    }
    $parent = $alias['parent'] ?? NULL;
    return is_string($parent) ? $parent : NULL;
  }

  /**
   * Returns an icon dimension with fallbacks to the set and Iconify default.
   *
   * @param array<mixed> $icon
   *   The icon data.
   * @param array<mixed> $set
   *   The icon set data.
   * @param string $name
   *   The dimension name, either "width" or "height".
   *
   * @return int|float
   *   The dimension value.
   */
  private function getDimension(array $icon, array $set, string $name): int|float {
    $value = $icon[$name] ?? $set[$name] ?? self::DEFAULT_SIZE;
    return is_int($value) || is_float($value) ? $value : self::DEFAULT_SIZE;
  }

  /**
   * Returns a value from the plugin configuration.
   *
   * @param string $key
   *   The configuration key.
   *
   * @return mixed
   *   The configuration value, or NULL when it is not set.
   */
  private function getConfigurationValue(string $key): mixed {
    $configuration = $this->configuration;
    if (!is_array($configuration)) {
      return NULL;
    }
    return $configuration[$key] ?? NULL;
  }

  /**
   * Returns the absolute path of the icon pack directory.
   *
   * @return string|null
   *   The absolute path, or NULL when it is not configured.
   */
  private function getAbsolutePath(): ?string {
    $absolute_path = $this->getConfigurationValue('absolute_path');
    return is_string($absolute_path) ? $absolute_path : NULL;
  }

  /**
   * Returns the id of the icon pack.
   *
   * @return string
   *   The icon pack id, or an empty string when it is not configured.
   */
  private function getPackId(): string {
    $id = $this->getConfigurationValue('id');
    return is_string($id) ? $id : '';
  }

}
