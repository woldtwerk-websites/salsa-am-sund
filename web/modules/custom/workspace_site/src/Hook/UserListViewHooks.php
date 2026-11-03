<?php

declare(strict_types=1);

namespace Drupal\workspace_site\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\views\ViewExecutable;

/**
 * Adjusts the user list view.
 *
 * The dedicated permission opens the user list to editors, who must not run
 * the bulk actions (block, unblock, roles, cancel): those would fail without
 * the account edit access, and the checkboxes suggest otherwise.
 */
final class UserListViewHooks {

  /**
   * Constructs the user list view hooks.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Implements hook_views_pre_view().
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view being executed.
   * @param string $display_id
   *   The display being executed.
   * @param array<mixed> $args
   *   The view arguments.
   */
  #[Hook('views_pre_view')]
  public function viewsPreView(ViewExecutable $view, string $display_id, array &$args): void {
    if ($view->id() !== 'user_admin_people' || $this->currentUser->hasPermission('administer users')) {
      return;
    }

    // This runs before the view builds its handlers, so the field is never
    // created for this request.
    $view->removeHandler($display_id, 'field', 'user_bulk_form');
  }

}
