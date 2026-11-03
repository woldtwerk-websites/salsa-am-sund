<?php

declare(strict_types=1);

namespace Drupal\workspace_site;

/**
 * Resolves the first and last name from OpenID Connect user information.
 *
 * The providers name the claims differently: Google sends given_name and
 * family_name, GitHub only a display name. The user name is derived from the
 * first and last name, so the fallbacks keep the generated user name
 * meaningful.
 */
final class OpenIdConnectNameResolver {

  /**
   * Resolves the first and last name from the user information.
   *
   * @param array<array-key, mixed> $userinfo
   *   The user information returned by the OpenID Connect provider.
   *
   * @return array{0: string, 1: string}
   *   The first and last name, empty strings when the claims carry none.
   */
  public static function resolve(array $userinfo): array {
    $first_name = self::stringValue($userinfo['given_name'] ?? NULL);
    $last_name = self::stringValue($userinfo['family_name'] ?? NULL);

    if ($first_name !== NULL || $last_name !== NULL) {
      return [$first_name ?? '', $last_name ?? ''];
    }

    $name = self::stringValue($userinfo['name'] ?? NULL);
    if ($name === NULL) {
      return ['', ''];
    }

    // The display name is already trimmed and collapsed, so a single space
    // separates the words.
    $parts = explode(' ', $name);
    if (count($parts) === 1) {
      return [$parts[0], ''];
    }

    $last = array_pop($parts);
    return [implode(' ', $parts), $last];
  }

  /**
   * Returns a trimmed claim value with collapsed whitespace.
   *
   * @param mixed $value
   *   The claim value.
   *
   * @return string|null
   *   The normalized value, or NULL when the claim is missing, not a string or
   *   empty.
   */
  private static function stringValue(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return $value === '' ? NULL : $value;
  }

}
