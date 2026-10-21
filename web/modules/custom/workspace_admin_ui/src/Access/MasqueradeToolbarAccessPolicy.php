<?php

declare(strict_types=1);

namespace Drupal\workspace_admin_ui\Access;

use Drupal\Core\Session\AccessPolicyBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\CalculatedPermissionsItem;
use Drupal\Core\Session\RefinableCalculatedPermissionsInterface;
use Drupal\masquerade\Masquerade;
use Drupal\masquerade\Session\MetadataBag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Grants "access toolbar" to the account a session is masquerading as.
 *
 * Stepping into an account without the permission would take the toolbar away,
 * and with it the Unmasquerade link. Core, gin and gin_toolbar all ask for the
 * permission directly, so granting it is the one place that reaches them all.
 *
 * Only the masqueraded account gets it: permissions are calculated for other
 * accounts during the same request too, and the session says nothing about
 * them. Hence the `user` context while masquerading — without it the result
 * for one account would be served to another with the same roles.
 */
final class MasqueradeToolbarAccessPolicy extends AccessPolicyBase {

  /**
   * Constructs the access policy.
   *
   * @param \Drupal\masquerade\Masquerade $masquerade
   *   The masquerade service.
   * @param \Symfony\Component\HttpFoundation\Session\SessionInterface $session
   *   The session.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(
    private readonly Masquerade $masquerade,
    private readonly SessionInterface $session,
    private readonly RequestStack $requestStack,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function calculatePermissions(AccountInterface $account, string $scope): RefinableCalculatedPermissionsInterface {
    $calculated_permissions = parent::calculatePermissions($account, $scope);
    if (!$this->hasMasqueradeSession() || !$this->masquerade->isMasquerading()) {
      return $calculated_permissions;
    }

    $calculated_permissions->addCacheContexts(['user']);
    // The session's uid is the masqueraded account; the current user may be
    // switched to whichever account is being calculated.
    if ((string) $account->id() === (string) $this->session->get('uid')) {
      $calculated_permissions->addItem(new CalculatedPermissionsItem(['access toolbar']));
    }

    return $calculated_permissions;
  }

  /**
   * {@inheritdoc}
   */
  public function getPersistentCacheContexts(): array {
    // Only web requests carry masquerade's session bag. Drush and other CLI
    // requests run with Symfony's metadata bag, where asking for the
    // `session.is_masquerading` cache context throws, so leave it out there.
    return $this->hasMasqueradeSession() ? ['session.is_masquerading'] : [];
  }

  /**
   * Whether the current request has a masquerade-backed web session.
   *
   * @return bool
   *   TRUE when the request session's metadata bag is masquerade's.
   */
  private function hasMasqueradeSession(): bool {
    $request = $this->requestStack->getCurrentRequest();

    return $request !== NULL
      && $request->hasSession()
      && $request->getSession()->getMetadataBag() instanceof MetadataBag;
  }

}
