<?php

declare(strict_types=1);

namespace Drupal\workspace_admin_ui\Plugin\Menu;

use Drupal\Core\Menu\MenuLinkDefault;
use Drupal\Core\Menu\StaticMenuLinkOverridesInterface;
use Drupal\workspace_admin_ui\MasqueradeLinks;

/**
 * A masquerade shortcut for an account looked up by name.
 *
 * A menu link definition cannot carry the uid: it is a fact about one
 * database, and the account behind a name may be recreated with another id.
 * The definition therefore stores the name, and the route parameter is
 * resolved when the link is used. The route's access check then decides per
 * request whether the link is shown, so the answer is not baked into the menu
 * tree.
 *
 * @see \Drupal\workspace_admin_ui\Access\MasqueradeTargetAccessCheck
 */
final class MasqueradeMenuLink extends MenuLinkDefault {

  /**
   * Constructs the menu link.
   *
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Menu\StaticMenuLinkOverridesInterface $static_override
   *   The static override storage.
   * @param \Drupal\workspace_admin_ui\MasqueradeLinks $masqueradeLinks
   *   The masquerade link helper.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    StaticMenuLinkOverridesInterface $static_override,
    protected readonly MasqueradeLinks $masqueradeLinks,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $static_override);
  }

  /**
   * {@inheritdoc}
   *
   * @return array{user: int|string}
   *   The uid of the account behind the configured name, or 0 when there is
   *   none.
   */
  public function getRouteParameters(): array {
    $options = $this->getOptions();
    $name = $options['account_name'] ?? '';
    $account = $this->masqueradeLinks->account(is_string($name) ? $name : '');

    // Without an account there is no uid to point at. Uid 0 does not resolve
    // to an account, so the route's parameter converter denies access and the
    // link stays out of the tree; `user_list` below brings it back once the
    // account exists.
    return ['user' => $account?->id() ?? 0];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    // The link is offered while masquerading is possible and hidden while it
    // runs, and masquerade's permissions decide the rest.
    return ['session.is_masquerading', 'user.permissions'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return $this->masqueradeLinks->cacheTags();
  }

}
