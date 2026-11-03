<?php

declare(strict_types=1);

namespace Drupal\workspace_site\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\user\UserInterface;
use Drupal\workspace_site\SsoAccount;

/**
 * Hook implementations for the account form of SSO accounts.
 */
final class SsoAccountFormHooks {

  use StringTranslationTrait;

  /**
   * Constructs the SSO account form hooks.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\workspace_site\SsoAccount $ssoAccount
   *   The SSO account helper.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The translation service.
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SsoAccount $ssoAccount,
    TranslationInterface $translation,
  ) {
    $this->stringTranslation = $translation;
  }

  /**
   * Implements hook_form_user_form_alter().
   *
   * The email address of an account created by an OpenID Connect provider is
   * owned by that provider, so the member cannot change it here: core would
   * ask for the current password, which SSO members do not have. Administrators
   * keep the field editable and can correct an address.
   *
   * @param array<mixed> $form
   *   The account form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   *
   * @param-out array<mixed> $form
   */
  #[Hook('form_user_form_alter')]
  public function formUserFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if ($this->currentUser->hasPermission('administer users')) {
      return;
    }

    $form_object = $form_state->getFormObject();
    $account = $form_object instanceof EntityFormInterface ? $form_object->getEntity() : NULL;
    if (!$account instanceof UserInterface) {
      return;
    }
    $provider = $this->ssoAccount->getCreatingProvider($account);
    if ($provider === NULL) {
      return;
    }
    $account_form = $form['account'] ?? NULL;
    $mail = is_array($account_form) ? ($account_form['mail'] ?? NULL) : NULL;
    if (!is_array($account_form) || !is_array($mail)) {
      return;
    }

    $client = $this->client($provider);
    $mail['#disabled'] = TRUE;
    $mail['#description'] = $this->t('Your email address comes from @provider. Change it there; it is updated on your next sign-in.', [
      '@provider' => $client !== NULL ? (string) $client->label() : $provider,
    ]);
    $account_form['mail'] = $mail;
    $form['account'] = $account_form;

    if ($client !== NULL) {
      CacheableMetadata::createFromRenderArray($form)
        ->addCacheableDependency($client)
        ->applyTo($form);
    }
  }

  /**
   * Loads the OpenID Connect client that created the account.
   *
   * @param string $provider
   *   The client entity ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The client, or NULL when the module is not installed or the client is
   *   gone.
   */
  private function client(string $provider): ?EntityInterface {
    if (!$this->entityTypeManager->hasDefinition('openid_connect_client')) {
      return NULL;
    }
    $client = $this->entityTypeManager->getStorage('openid_connect_client')->load($provider);

    return $client instanceof EntityInterface ? $client : NULL;
  }

}
