<?php

declare(strict_types=1);

namespace Drupal\workspace_site\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Approves a registration that awaits approval.
 */
final class ApproveRegistrationController extends ControllerBase {

  /**
   * Activates a pending registration.
   *
   * The route only grants access while the account awaits approval, so the
   * account is always pending here.
   *
   * @param \Drupal\user\UserInterface $user
   *   The account to approve.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect to the user list. The destination query parameter of the
   *   operation link wins over this redirect.
   */
  public function approve(UserInterface $user): RedirectResponse {
    // Activation sends core's status_activated mail.
    $user->activate()->save();
    $this->messenger()->addStatus($this->t('The account of %name has been approved.', [
      '%name' => $user->getDisplayName(),
    ]));

    return $this->redirect('entity.user.collection');
  }

}
