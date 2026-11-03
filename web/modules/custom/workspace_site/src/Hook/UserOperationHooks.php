<?php

declare(strict_types=1);

namespace Drupal\workspace_site\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Drupal\workspace_site\Access\RegistrationApprovalAccess;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Adds the approve and delete operations to user rows.
 *
 * Administrators find the operations on the registrations dashboard and on the
 * user list alike. Editors with the registration permission find them on
 * pending registrations only.
 */
final class UserOperationHooks {

  use StringTranslationTrait;

  /**
   * Constructs the user operation hooks.
   *
   * @param \Drupal\workspace_site\Access\RegistrationApprovalAccess $registrationApprovalAccess
   *   The registration approval access check.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The translation service.
   */
  public function __construct(
    private readonly RegistrationApprovalAccess $registrationApprovalAccess,
    private readonly AccountProxyInterface $currentUser,
    private readonly RequestStack $requestStack,
    TranslationInterface $translation,
  ) {
    $this->stringTranslation = $translation;
  }

  /**
   * Implements hook_entity_operation().
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity the operations are built for.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   The cacheable metadata of the operations.
   *
   * @return array<string, array<string, mixed>>
   *   The operations, keyed by operation name.
   */
  #[Hook('entity_operation')]
  public function entityOperation(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL): array {
    if (!$entity instanceof UserInterface || $entity->id() === NULL) {
      return [];
    }

    $operations = [];
    $cacheability?->addCacheContexts(['user.permissions']);

    $registration_access = $this->registrationApprovalAccess->access($this->currentUser, $entity);
    $cacheability?->addCacheableDependency($registration_access);

    $delete_access = $entity->access('delete', return_as_object: TRUE);
    $cacheability?->addCacheableDependency($delete_access);
    if ($delete_access->isAllowed()) {
      $operations['delete'] = [
        'title' => $this->t('Delete'),
        'url' => Url::fromRoute('entity.user.cancel_form', ['user' => $entity->id()], [
          'query' => ['user_cancel_method' => 'user_cancel_delete'],
        ]),
        'weight' => 100,
      ];
    }
    elseif ($registration_access->isAllowed()) {
      $operations['delete'] = [
        'title' => $this->t('Delete'),
        'url' => Url::fromRoute('workspace_site.delete_registration', ['user' => $entity->id()]),
        'weight' => 100,
      ];
    }

    if (!$registration_access->isAllowed()) {
      return $operations;
    }

    $operations['approve'] = [
      'title' => $this->t('Approve'),
      'url' => Url::fromRoute('workspace_site.approve_registration', ['user' => $entity->id()]),
      // Before core's "Edit" (10), so it is the dropbutton's primary action.
      'weight' => -100,
    ];

    return $operations;
  }

  /**
   * Implements hook_form_FORM_ID_alter() for the account cancel form.
   *
   * The delete operation opens the form with the delete method preselected.
   *
   * @param array<mixed> $form
   *   The cancel form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   */
  #[Hook('form_user_cancel_form_alter')]
  public function alterCancelForm(array &$form, FormStateInterface $form_state, string $form_id): void {
    $method = $this->requestStack->getCurrentRequest()?->query->get('user_cancel_method');
    if (!is_string($method) || !$this->currentUser->hasPermission('administer users')) {
      return;
    }
    if (!is_array($form['user_cancel_method'] ?? NULL)) {
      return;
    }
    $methods = user_cancel_methods();
    $options = $methods['#options'] ?? NULL;
    if (!is_array($options) || !isset($options[$method])) {
      return;
    }

    $form['user_cancel_method']['#default_value'] = $method;
    CacheableMetadata::createFromRenderArray($form)
      ->addCacheContexts(['url.query_args:user_cancel_method'])
      ->applyTo($form);
  }

}
