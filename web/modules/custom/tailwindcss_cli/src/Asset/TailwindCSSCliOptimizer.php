<?php

namespace Drupal\tailwindcss_cli\Asset;

use Drupal\Core\Asset\CssOptimizer;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Optimizes a CSS asset.
 */
class TailwindCSSCliOptimizer extends CssOptimizer {

  /**
   * Constructs a CssOptimizer.
   *
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   */
  public function __construct(
    FileUrlGeneratorInterface $file_url_generator,
  ) {
    parent::__construct($file_url_generator);
  }

  /**
   * {@inheritdoc}
   */
  public function optimize(array $css_asset) {
    if ($css_asset['type'] != 'file') {
      throw new \Exception('Only file CSS assets can be optimized.');
    }
    if (!$css_asset['preprocess']) {
      throw new \Exception('Only file CSS assets with preprocessing enabled can be optimized.');
    }

    return $this->chooseStrategy($css_asset);
  }

  /**
   * Processes CSS file and adds base URLs to any relative resource paths.
   *
   * @param array $css_asset
   *   A CSS asset.
   *
   * @return string
   *   The asset's cleaned/optimized contents.
   */
  protected function chooseStrategy($css_asset) {
    $contents = @file_get_contents($css_asset['data'], TRUE);
    preg_match('#@tailwindcss-placeholder|@reference|@import\s(?:\"|\')tailwindcss#', $contents, $matches);

    return count($matches)
      ? $this->compileFile($css_asset)
      : $this->processFile($css_asset);
  }

  /**
   * Processes CSS file and adds base URLs to any relative resource paths.
   *
   * @param array $css_asset
   *   A CSS asset. The array should contain the `data` key where the value
   *   should be the path to the CSS file relative to the Drupal root. This is
   *   an example of the `data` key's value,
   *   "core/assets/vendor/normalize-css/normalize.css".
   *
   * @return string
   *   The asset's cleaned/optimized contents.
   */
  protected function compileFile($css_asset): string {
    $binary = DRUPAL_ROOT . '/../node_modules/.bin/tailwindcss';
    $file = DRUPAL_ROOT . "/" . $css_asset['data'];
    $cwd = DRUPAL_ROOT . '/' . dirname($css_asset['data']);

    $process = new Process([$binary, '-i', $file, '-m', '-cwd', $cwd]);

    try {
      $process->run();

      if (!$process->isSuccessful()) {
        throw new ProcessFailedException($process);
      }

      $contents = $process->getOutput();

      // Get the parent directory of this file, relative to the Drupal root.
      $css_base_path = substr($css_asset['data'], 0, strrpos($css_asset['data'], '/'));

      // Store base path.
      $this->rewriteFileURIBasePath = $css_base_path . '/';

      // Anchor all paths in the CSS with its base URL, ignoring external
      // and absolute paths and paths starting with '#'.
      return preg_replace_callback('/url\(\s*[\'"]?(?![a-z]+:|\/+|#|%23)([^\'")]+)[\'"]?\s*\)/i', [
        $this,
        'rewriteFileURI',
      ], $contents);
    }
    catch (\Exception $e) {
      \Drupal::logger('tailwindcss_cli')->error($e->getMessage());
    }

    return '';
  }

}
