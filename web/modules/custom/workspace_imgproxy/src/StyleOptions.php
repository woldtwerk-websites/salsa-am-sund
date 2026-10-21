<?php

declare(strict_types=1);

namespace Drupal\workspace_imgproxy;

use Drupal\image\ImageStyleInterface;
use Psr\Log\LoggerInterface;

/**
 * Translates the effects of an image style into imgproxy processing options.
 *
 * The image styles stay the single source of truth for what a derivative looks
 * like — they are just no longer applied by a toolkit, they are described to
 * imgproxy. Only the core effects are known here; an effect this does not
 * recognise is logged and skipped, because there is no way to reproduce it.
 */
class StyleOptions {

  /**
   * Maps the anchor of a crop effect to an imgproxy gravity.
   */
  const GRAVITY = [
    'left-top' => 'nowe',
    'center-top' => 'no',
    'right-top' => 'noea',
    'left-center' => 'we',
    'center-center' => 'ce',
    'right-center' => 'ea',
    'left-bottom' => 'sowe',
    'center-bottom' => 'so',
    'right-bottom' => 'soea',
  ];

  /**
   * Constructs the translator.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    protected LoggerInterface $logger,
  ) {
  }

  /**
   * The processing options of an image style.
   *
   * @param \Drupal\image\ImageStyleInterface $style
   *   The image style.
   *
   * @return list<string>
   *   The options, in the order the effects are weighted.
   */
  public function forStyle(ImageStyleInterface $style): array {
    $options = [];
    foreach ($style->getEffects() as $effect) {
      $configuration = $effect->getConfiguration();
      $data = is_array($configuration['data'] ?? NULL) ? $configuration['data'] : [];
      foreach ($this->forEffect((string) $effect->getPluginId(), $data, $style) as $option) {
        $options[] = $option;
      }
    }

    return $options;
  }

  /**
   * The processing options of a single effect.
   *
   * @param string $id
   *   The plugin ID of the effect.
   * @param array<mixed> $data
   *   The configuration of the effect.
   * @param \Drupal\image\ImageStyleInterface $style
   *   The image style the effect belongs to, for the log message.
   *
   * @return list<string>
   *   The options, empty when the effect needs none.
   */
  protected function forEffect(string $id, array $data, ImageStyleInterface $style): array {
    $width = $this->dimension($data, 'width');
    $height = $this->dimension($data, 'height');

    switch ($id) {
      case 'image_scale':
        // Fit keeps the aspect ratio inside the box, which is what scaling to
        // one dimension does, and the box may be open on one side.
        return ['rs:fit:' . $width . ':' . $height . ':' . (int) !empty($data['upscale'])];

      case 'image_resize':
        // Enlarging, because this effect resizes to the exact box whether the
        // original is larger or smaller.
        return ['rs:force:' . $width . ':' . $height . ':1'];

      case 'image_scale_and_crop':
        // Enlarging for the same reason: the effect fills the box, and with
        // enlarging off imgproxy hands back the untouched original instead —
        // which is the one thing a fixed aspect ratio must never do.
        return ['rs:fill:' . $width . ':' . $height . ':1', 'g:' . $this->gravity($data)];

      case 'image_crop':
        return ['c:' . $width . ':' . $height . ':' . $this->gravity($data)];

      case 'image_desaturate':
        return ['sa:0'];

      case 'image_rotate':
        $degrees = is_numeric($data['degrees'] ?? NULL) ? (int) $data['degrees'] : 0;
        if ($degrees % 90 !== 0) {
          $this->unsupported($id, $style, 'imgproxy rotates in steps of 90 degrees only');

          return [];
        }

        return $degrees === 0 ? [] : ['rot:' . (($degrees % 360) + 360) % 360];

      case 'image_convert':
        $extension = is_string($data['extension'] ?? NULL) ? $data['extension'] : '';

        return $extension === '' ? [] : ['f:' . $extension];

      case 'image_convert_avif':
        // "Convert to AVIF, with a fallback if AVIF is not supported" is what
        // imgproxy does on its own from the Accept header of the request, see
        // IMGPROXY_AUTO_AVIF in .ddev/docker-compose.imgproxy.yaml. Pinning a
        // format here would take the fallback away.
        return [];

      default:
        $this->unsupported($id, $style, 'no imgproxy option is known for it');

        return [];
    }
  }

  /**
   * One dimension of an effect, as imgproxy spells an unset one.
   *
   * @param array<mixed> $data
   *   The configuration of the effect.
   * @param string $key
   *   Either `width` or `height`.
   *
   * @return int
   *   The dimension, or 0 for "no limit".
   */
  protected function dimension(array $data, string $key): int {
    return is_numeric($data[$key] ?? NULL) ? (int) $data[$key] : 0;
  }

  /**
   * The gravity of a cropping effect.
   *
   * @param array<mixed> $data
   *   The configuration of the effect.
   *
   * @return string
   *   The imgproxy gravity, centered when the anchor is unknown.
   */
  protected function gravity(array $data): string {
    $anchor = is_string($data['anchor'] ?? NULL) ? $data['anchor'] : '';

    return self::GRAVITY[$anchor] ?? 'ce';
  }

  /**
   * Logs an effect that cannot be translated.
   *
   * @param string $id
   *   The plugin ID of the effect.
   * @param \Drupal\image\ImageStyleInterface $style
   *   The image style the effect belongs to.
   * @param string $reason
   *   Why it is skipped.
   */
  protected function unsupported(string $id, ImageStyleInterface $style, string $reason): void {
    $this->logger->warning('The %effect effect of the %style image style is not applied: @reason.', [
      '%effect' => $id,
      '%style' => $style->id(),
      '@reason' => $reason,
    ]);
  }

}
