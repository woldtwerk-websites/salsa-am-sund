<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_site\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\workspace_site\OpenIdConnectNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the OpenID Connect name resolver.
 */
#[CoversClass(OpenIdConnectNameResolver::class)]
#[Group('workspace_site')]
final class OpenIdConnectNameResolverTest extends UnitTestCase {

  /**
   * Tests resolving the first and last name from the user information.
   *
   * @param array<string, mixed> $userinfo
   *   The provider's user information.
   * @param array{0: string, 1: string} $expected
   *   The expected first and last name.
   */
  #[DataProvider('userinfoProvider')]
  public function testResolve(array $userinfo, array $expected): void {
    self::assertSame($expected, OpenIdConnectNameResolver::resolve($userinfo));
  }

  /**
   * Provides user information and the expected name parts.
   *
   * @return array<string, array{array<string, mixed>, array{0: string, 1: string}}>
   *   The test cases.
   */
  public static function userinfoProvider(): array {
    return [
      'Google claims' => [
        [
          'given_name' => 'Maria',
          'family_name' => 'Pérez',
          'name' => 'Maria Pérez',
        ],
        ['Maria', 'Pérez'],
      ],
      'Facebook claims' => [
        [
          'first_name' => 'John',
          'last_name' => 'Doe',
          'name' => 'John Doe',
        ],
        ['John', 'Doe'],
      ],
      'GitHub full name' => [
        ['name' => 'Maria José Pérez'],
        ['Maria José', 'Pérez'],
      ],
      'Single word name' => [
        ['name' => 'Cher'],
        ['Cher', ''],
      ],
      'Login only' => [
        ['login' => 'octocat'],
        ['', ''],
      ],
      'No user information' => [
        [],
        ['', ''],
      ],
      'Non-string values are ignored' => [
        [
          'given_name' => 123,
          'family_name' => ['Pérez'],
          'name' => 42,
        ],
        ['', ''],
      ],
      'Empty claims are ignored' => [
        [
          'given_name' => '   ',
          'family_name' => '',
          'name' => '',
        ],
        ['', ''],
      ],
      'Whitespace is trimmed and collapsed' => [
        ['given_name' => '  Maria ', 'family_name' => " José\tPérez  "],
        ['Maria', 'José Pérez'],
      ],
      'Whitespace in the display name is collapsed' => [
        ['name' => "  Maria   José\tPérez  "],
        ['Maria José', 'Pérez'],
      ],
    ];
  }

}
