<?php

declare(strict_types=1);

namespace Drupal\workspace_site;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Detects the account form shown right after a one-time login.
 *
 * Core stores a token in the session and redirects to the account edit form
 * with that token as query argument, so the user can set a new password
 * without entering the current one. The theme and the form hooks show only the
 * password fields on that page, which requires the same check that
 * \Drupal\user\AccountForm does.
 *
 * @see \Drupal\user\Controller\UserController::resetPassLogin()
 * @see \Drupal\user\AccountForm::form()
 */
final class PasswordResetDetector {

  /**
   * The cache contexts the detection depends on.
   *
   * Every render array whose output differs between the password reset form and
   * the regular account form must add these contexts.
   *
   * @var string[]
   */
  public const CACHE_CONTEXTS = [
    'route',
    'url.query_args:pass-reset-token',
    'session',
  ];

  /**
   * The result of the detection, memoized per request.
   */
  private ?bool $isPasswordReset = NULL;

  /**
   * Constructs the password reset detector.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   */
  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Determines whether the current page is the password reset form.
   *
   * The form is the account edit form of the current user, opened with the
   * token that the one-time login stored in the session.
   */
  public function isPasswordReset(): bool {
    if ($this->isPasswordReset !== NULL) {
      return $this->isPasswordReset;
    }

    if ($this->routeMatch->getRouteName() !== 'entity.user.edit_form') {
      return $this->isPasswordReset = FALSE;
    }

    $user = $this->routeMatch->getParameter('user');
    if (!$user instanceof UserInterface || $user->id() === NULL) {
      return $this->isPasswordReset = FALSE;
    }

    $uid = (int) $user->id();
    if ($uid !== (int) $this->currentUser->id()) {
      return $this->isPasswordReset = FALSE;
    }

    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL || !$request->hasSession()) {
      return $this->isPasswordReset = FALSE;
    }

    $token = $request->query->get('pass-reset-token');
    if (!is_string($token) || $token === '') {
      return $this->isPasswordReset = FALSE;
    }

    $session_value = $request->getSession()->get('pass_reset_' . $uid);
    if (!is_string($session_value)) {
      return $this->isPasswordReset = FALSE;
    }

    return $this->isPasswordReset = hash_equals($session_value, $token);
  }

}
