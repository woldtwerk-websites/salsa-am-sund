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
 * Plugin implementation of the icon_extractor.
 *
 * @internal
 *   This API is experimental.
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
   * {@inheritdoc}
   */
  public function discoverIcons(): array {
    $files = $this->configuration['config']['sources'];

    if (empty($files)) {
      return [];
    }

    $icons = [];
    foreach ($files as $file) {
      // Read file with drupal FileSystem.
      $content = file_get_contents($this->configuration['absolute_path'] . '/' . $file);
      try {
        $json = json_decode($content, TRUE, 512, JSON_THROW_ON_ERROR);
      }
      catch (\JsonException $e) {
        throw new \RuntimeException('Invalid JSON file: ' . $file);
      }
      foreach ($json['icons'] as $id => $icon) {
        $iconId = IconDefinition::createIconId($this->configuration['id'], strval($id));
        $icons[$iconId] = [
          'absolute_path' => $this->configuration['absolute_path'],
          'file' => $file,
          'normalized_icon_id' => strval($id),
        ];
      }
      foreach ($json['aliases'] as $id => $icon) {
        $iconId = IconDefinition::createIconId($this->configuration['id'], strval($id));
        $icons[$iconId] = [
          'absolute_path' => $this->configuration['absolute_path'],
          'file' => $file,
          'normalized_icon_id' => $icon["parent"],
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
   * @param array $meta
   *   The svg meta information.
   *
   * @return string
   *   The encoded svg data uri.
   */
  public function encodeSvg(string $svg, array $meta): string {
    $attributes = new Attribute();
    if (isset($meta['height']) && isset($meta['width'])) {
      $attributes->setAttribute('viewBox', '0 0 ' . $meta['width'] . ' ' . $meta['height']);
      $attributes->setAttribute('height', $meta['height']);
      $attributes->setAttribute('width', $meta['width']);
    }
    return 'data:image/svg+xml;base64,'
      . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" '
      . $attributes->__toString()
      . '>' . $svg . '</svg>');
  }

  /**
   * {@inheritdoc}
   */
  public function loadIcon(array $icon_data): ?IconDefinitionInterface {
    if (!isset($icon_data['icon_id'])) {
      return NULL;
    }

    $content = file_get_contents($this->configuration['absolute_path'] . '/' . $icon_data["file"]);
    $json = json_decode($content, TRUE);
    $meta = [
      'height' => $json['height'],
      'width' => $json['width'],
    ];
    $body = $json['icons'][$icon_data['normalized_icon_id']]['body'];

    if (!$svg_data = $this->extractSvg($body, $meta)) {
      return NULL;
    }

    if (!array_key_exists('source', $icon_data)) {
      $icon_data['source'] = NULL;
    }

    return $this->createIcon(
      $icon_data['icon_id'],
      $this->encodeSvg($body, $meta),
      $icon_data['group'] ?? NULL,
      $svg_data,
    );
  }

  /**
   * Extract svg values, simply exclude parent <svg>.
   *
   * @return array|null
   *   The SVG `content` as string and `viewbox` value if any.
   */
  private function extractSvg(string $body, array $meta): ?array {
    $attributes = new Attribute();
    if (isset($meta['height']) && isset($meta['width'])) {
      $attributes->setAttribute('viewBox', '0 0 ' . $meta['width'] . ' ' . $meta['height']);
    }

    $return = [
      'content' => $body,
      'attributes' => $attributes,
    ];

    if (empty($return['content'])) {
      return NULL;
    }

    $return['content'] = new FormattableMarkup($return['content'], []);

    return $return;
  }

}
