<?php

declare(strict_types=1);

namespace Drupal\workspace_site\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\UserInterface;

/**
 * Provides the page title of the user account edit form.
 */
final class UserTitleController extends ControllerBase {

  /**
   * Returns the title of the account edit form.
   *
   * Members editing their own account get a generic title. Editing another
   * account keeps the account name, like the core user title.
   *
   * @param \Drupal\user\UserInterface|null $user
   *   The account being edited.
   *
   * @return string|\Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   The page title.
   *
   * @see \Drupal\user\Controller\UserController::userTitle()
   */
  public function editTitle(?UserInterface $user = NULL): string|TranslatableMarkup|null {
    if ($user === NULL) {
      return NULL;
    }

    if ((int) $user->id() === (int) $this->currentUser()->id()) {
      return $this->t('Edit profile');
    }

    $name = $user->getDisplayName();
    return $name instanceof TranslatableMarkup ? $name : (string) $name;
  }

}
