<?php

declare(strict_types=1);

namespace Drupal\workspace_imgproxy;

use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperManager;

/**
 * Builds imgproxy URLs.
 *
 * An imgproxy URL is `{base}/{signature}/{options}/{source}`, where the source
 * is the base64url encoded URL of the original — a `local://` one by default,
 * because imgproxy reads the files directory rather than fetching over HTTP,
 * see .ddev/docker-compose.imgproxy.yaml.
 *
 * A source base URL turns that into an HTTP one below it, which is what makes
 * stage_file_proxy work: a file missing from the files directory is a 404
 * imgproxy never sees — nothing but Drupal's own kernel can fetch it from the
 * staging origin. Only development sets it.
 *
 * The base URL, the source base URL, the signing key and the signing salt come
 * from `$settings['imgproxy']` — settings.local.php included, which is what a
 * developer overrides locally — and fall back to the IMGPROXY_BASE_URL,
 * IMGPROXY_SOURCE_BASE_URL, IMGPROXY_KEY and IMGPROXY_SALT environment
 * variables.
 *
 * The key and the salt are secrets. Locally they are the throwaway pair in
 * .ddev/.env, which is why signed URLs can be developed against at all; a
 * deployed environment sets its own, and those never belong in this
 * repository. Without a key and a salt imgproxy accepts unsigned URLs.
 */
class UrlBuilder {

  /**
   * The base URL of the imgproxy instance, without a trailing slash.
   */
  protected readonly string $baseUrl;

  /**
   * The base URL originals are read over HTTP from, without a trailing slash.
   *
   * An empty string reads them off the local filesystem instead.
   */
  protected readonly string $sourceBaseUrl;

  /**
   * The signing key, hex encoded, or an empty string when URLs are unsigned.
   */
  protected readonly string $key;

  /**
   * The signing salt, hex encoded, or an empty string when URLs are unsigned.
   */
  protected readonly string $salt;

  /**
   * Constructs the builder.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings.
   */
  public function __construct(
    protected Settings $settings,
  ) {
    $this->baseUrl = rtrim($this->value('base_url', 'IMGPROXY_BASE_URL'), '/');
    $this->sourceBaseUrl = rtrim($this->value('source_base_url', 'IMGPROXY_SOURCE_BASE_URL'), '/');
    $this->key = $this->value('key', 'IMGPROXY_KEY');
    $this->salt = $this->value('salt', 'IMGPROXY_SALT');
  }

  /**
   * Whether imgproxy is configured for this environment.
   *
   * @return bool
   *   TRUE when a base URL is known.
   */
  public function isEnabled(): bool {
    return $this->baseUrl !== '';
  }

  /**
   * Builds the URL of one derivative.
   *
   * @param string $uri
   *   The URI of the original, a stream wrapper one such as `public://a.jpg`.
   * @param list<string> $options
   *   The imgproxy processing options, see
   *   \Drupal\workspace_imgproxy\StyleOptions::forStyle().
   *
   * @return string|null
   *   The URL, or NULL when imgproxy cannot serve this URI — either because it
   *   is not configured, or because the file is not one imgproxy can read.
   */
  public function build(string $uri, array $options): ?string {
    $source = $this->source($uri);
    if (!$this->isEnabled() || $source === NULL) {
      return NULL;
    }

    // A processing option is only omitted for an unmodified original, which
    // imgproxy still has to be told about, hence the empty resize.
    $path = '/' . implode('/', $options ?: ['rs:fit:0:0:0']) . '/' . $this->encode($source);

    return $this->baseUrl . '/' . $this->signature($path) . $path;
  }

  /**
   * The source URL imgproxy reads the original from.
   *
   * @param string $uri
   *   The URI of the original.
   *
   * @return string|null
   *   The URL below the source base URL, or a `local://` one while none is
   *   set, or NULL for a file outside the public files directory, which
   *   imgproxy has no access to.
   */
  protected function source(string $uri): ?string {
    $target = StreamWrapperManager::getTarget($uri);
    if (StreamWrapperManager::getScheme($uri) !== 'public' || $target === FALSE) {
      return NULL;
    }

    if ($this->sourceBaseUrl === '') {
      return 'local:///' . $target;
    }

    // A path imgproxy fetches over HTTP is a URL, so its segments have to be
    // encoded — a `local://` one is read as a path and must not be.
    return $this->sourceBaseUrl . '/' . implode('/', array_map('rawurlencode', explode('/', $target)));
  }

  /**
   * Signs the path of a URL.
   *
   * @param string $path
   *   The path, including its leading slash.
   *
   * @return string
   *   The signature, or the literal `insecure` while no key and salt are set.
   */
  protected function signature(string $path): string {
    if ($this->key === '' || $this->salt === '') {
      return 'insecure';
    }

    $key = hex2bin($this->key);
    $salt = hex2bin($this->salt);
    if ($key === FALSE || $salt === FALSE) {
      throw new \UnexpectedValueException('IMGPROXY_KEY and IMGPROXY_SALT have to be hex encoded.');
    }

    return $this->encode(hash_hmac('sha256', $salt . $path, $key, TRUE));
  }

  /**
   * Encodes a value the way imgproxy reads it, base64url without padding.
   *
   * @param string $value
   *   The value.
   *
   * @return string
   *   The encoded value.
   */
  protected function encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
  }

  /**
   * Reads one setting, from the settings first and the environment second.
   *
   * @param string $name
   *   The key below `$settings['imgproxy']`.
   * @param string $variable
   *   The name of the environment variable holding the same value.
   *
   * @return string
   *   The value, or an empty string when neither is set.
   */
  protected function value(string $name, string $variable): string {
    $imgproxy = $this->settings->get('imgproxy');
    $setting = is_array($imgproxy) ? $imgproxy[$name] ?? NULL : NULL;
    if (is_string($setting) && trim($setting) !== '') {
      return trim($setting);
    }

    $value = getenv($variable);

    return is_string($value) ? trim($value) : '';
  }

}
