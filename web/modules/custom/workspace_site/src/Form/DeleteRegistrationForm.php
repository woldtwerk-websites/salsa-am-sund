<?php

declare(strict_types=1);

namespace Drupal\workspace_site\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms the deletion of a pending registration.
 */
final class DeleteRegistrationForm extends ConfirmFormBase {

  /**
   * The registration to delete.
   */
  protected ?UserInterface $user = NULL;

  /**
   * Constructs the delete registration form.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    protected readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $module_handler = $container->get('module_handler');
    \assert($module_handler instanceof ModuleHandlerInterface);

    return new static($module_handler);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\user\UserInterface|null $user
   *   The registration to delete.
   *
   * @return array<mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?UserInterface $user = NULL): array {
    $this->user = $user;

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'workspace_site_delete_registration';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Delete the registration of %name?', [
      '%name' => $this->user?->getDisplayName() ?? '',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('Delete the account and its content. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('dashboard');
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->user === NULL) {
      return;
    }

    $this->moduleHandler->loadInclude('user', 'module');
    user_cancel([], (int) $this->user->id(), 'user_cancel_delete');
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
