<?php

declare(strict_types=1);

namespace Drupal\workspace_site\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\openid_connect\OpenIDConnectSessionInterface;
use Drupal\user\UserInterface;
use Drupal\workspace_site\OpenIdConnectNameResolver;
use Drupal\workspace_site\PendingApprovalMailer;
use Drupal\workspace_site\PendingRegistration;
use Drupal\workspace_site\SsoAccount;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Hook implementations for OpenID Connect logins.
 */
final class OpenIdConnectHooks {

  use StringTranslationTrait;

  /**
   * The account that awaits the pending approval mail after its save.
   */
  private ?int $pendingApprovalUid = NULL;

  /**
   * The logger channel of this module.
   */
  private readonly LoggerChannelInterface $logger;

  /**
   * Constructs the OpenID Connect hooks.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container, used to load the optional OpenID Connect session.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\workspace_site\PendingRegistration $pendingRegistration
   *   The pending registration tracker.
   * @param \Drupal\workspace_site\PendingApprovalMailer $pendingApprovalMailer
   *   The pending approval mailer.
   * @param \Drupal\workspace_site\SsoAccount $ssoAccount
   *   The SSO account helper.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The translation service.
   */
  public function __construct(
    private readonly ContainerInterface $container,
    private readonly MessengerInterface $messenger,
    private readonly PendingRegistration $pendingRegistration,
    private readonly PendingApprovalMailer $pendingApprovalMailer,
    private readonly SsoAccount $ssoAccount,
    LoggerChannelFactoryInterface $loggerFactory,
    TranslationInterface $translation,
  ) {
    $this->stringTranslation = $translation;
    $this->logger = $loggerFactory->get('workspace_site');
  }

  /**
   * Implements hook_openid_connect_userinfo_save().
   *
   * The hook runs before OpenIDConnect::saveUserinfo() saves the account, so
   * UsernameHooks::presave() derives the user name from these names. Existing
   * values are kept: a member may have edited their name after the login.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account.
   * @param array<string, mixed> $context
   *   The context of the login, including the provider's user information.
   */
  #[Hook('openid_connect_userinfo_save')]
  public function userinfoSave(UserInterface $account, array $context): void {
    $userinfo = $context['userinfo'] ?? NULL;
    if (!is_array($userinfo)) {
      return;
    }

    [$first_name, $last_name] = OpenIdConnectNameResolver::resolve($userinfo);
    self::setEmptyField($account, 'field_first_name', $first_name);
    self::setEmptyField($account, 'field_last_name', $last_name);

    // The provider is the source of the email address for accounts it created.
    // The account is saved right after this hook, so the address is changed on
    // the object and the save persists it.
    $client_id = $context['plugin_id'] ?? NULL;
    if (is_string($client_id) && $client_id !== '') {
      if (!empty($context['is_new'])) {
        $this->ssoAccount->markCreatedBy($account, $client_id);
      }
      if ($this->ssoAccount->syncMail($account, $client_id, $userinfo['email'] ?? NULL)) {
        $this->logger->info('Synchronized the email address of user @uid from @provider.', [
          '@uid' => $account->id(),
          '@provider' => $client_id,
        ]);
      }
    }

    // A new account is blocked until an administrator approves it.
    if ($account->id() === NULL || !$account->isBlocked() || (int) $account->getLastLoginTime() !== 0) {
      return;
    }

    // When the provider did not send a usable name, ask for it before the
    // approval: the account keeps the login name until then.
    if ($this->pendingRegistration->needsName($account)) {
      $this->pendingRegistration->mark($account);
      $path = '/' . Url::fromRoute('workspace_site.complete_registration')
        ->getInternalPath();
      $this->openIdConnectSession()?->saveTargetLinkUri($path);
      return;
    }

    // Core sends the pending approval mail from RegisterForm::save(), which
    // OpenID Connect does not use. The account is saved right after this hook,
    // and only the save regenerates the user name from the names, so the mail
    // is sent from user_update: that way its display name token already holds
    // the final name. The mailer stores a flag, so a member who completes the
    // registration form later does not get the mail twice.
    if (!empty($context['is_new'])) {
      $this->pendingApprovalUid = (int) $account->id();
    }
  }

  /**
   * Implements hook_user_update().
   *
   * @param \Drupal\user\UserInterface $account
   *   The saved account.
   */
  #[Hook('user_update')]
  public function userUpdate(UserInterface $account): void {
    if ($this->pendingApprovalUid === NULL || $this->pendingApprovalUid !== (int) $account->id()) {
      return;
    }
    $this->pendingApprovalUid = NULL;
    $this->pendingApprovalMailer->sendOnce($account);
  }

  /**
   * Implements hook_openid_connect_post_authorize().
   *
   * A provider may not send a usable first or last name (GitHub only sends a
   * login name, for example). Send these members to their account form and ask
   * them for the missing name.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account.
   * @param array<string, mixed> $context
   *   The context of the login.
   */
  #[Hook('openid_connect_post_authorize')]
  public function postAuthorize(UserInterface $account, array $context): void {
    if (!$account->hasField('field_first_name') || !$account->hasField('field_last_name')) {
      return;
    }

    $first_name_empty = trim($account->get('field_first_name')->getString()) === '';
    $last_name_empty = trim($account->get('field_last_name')->getString()) === '';
    if (!$first_name_empty && !$last_name_empty) {
      return;
    }

    if ($first_name_empty && $last_name_empty) {
      $message = $this->t('Please add your first and last name.');
    }
    elseif ($first_name_empty) {
      $message = $this->t('Please add your first name.');
    }
    else {
      $message = $this->t('Please add your last name.');
    }
    $this->messenger->addWarning($message);

    // The redirect controller wraps the destination in "internal:/" and adds
    // the language prefix, so the path has to be relative to the base path.
    // saveTargetLinkUri() requires a leading slash to recognize the path as an
    // internal URL; it stores the destination in the session itself.
    $path = '/' . Url::fromRoute('entity.user.edit_form', ['user' => $account->id()])
      ->getInternalPath();
    $this->openIdConnectSession()?->saveTargetLinkUri($path);
  }

  /**
   * Returns the OpenID Connect session.
   *
   * The module is not a hard dependency of this module, so the service is
   * loaded lazily. The hook only runs during an OpenID Connect login.
   *
   * @return \Drupal\openid_connect\OpenIDConnectSessionInterface|null
   *   The session, or NULL when the module is not installed.
   */
  private function openIdConnectSession(): ?OpenIDConnectSessionInterface {
    if (!$this->container->has('openid_connect.session')) {
      return NULL;
    }

    $session = $this->container->get('openid_connect.session');
    return $session instanceof OpenIDConnectSessionInterface ? $session : NULL;
  }

  /**
   * Sets a name field when it exists and is still empty.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account.
   * @param string $field_name
   *   The machine name of the field.
   * @param string $value
   *   The value to set.
   */
  private static function setEmptyField(UserInterface $account, string $field_name, string $value): void {
    if ($value === '' || !$account->hasField($field_name)) {
      return;
    }
    if (trim($account->get($field_name)->getString()) !== '') {
      return;
    }
    $account->set($field_name, $value);
  }

}
