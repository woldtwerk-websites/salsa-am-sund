<?php

declare(strict_types=1);

namespace Drupal\workspace_site\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\UserInterface;
use Drupal\workspace_site\PendingApprovalMailer;
use Drupal\workspace_site\PendingRegistration;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Asks a pending OpenID Connect account for its missing name.
 */
final class CompleteRegistrationForm extends FormBase {

  /**
   * Constructs the registration completion form.
   *
   * @param \Drupal\workspace_site\PendingRegistration $pendingRegistration
   *   The pending registration tracker.
   * @param \Drupal\workspace_site\PendingApprovalMailer $pendingApprovalMailer
   *   The pending approval mailer.
   */
  public function __construct(
    protected readonly PendingRegistration $pendingRegistration,
    protected readonly PendingApprovalMailer $pendingApprovalMailer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(PendingRegistration::class),
      $container->get(PendingApprovalMailer::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'workspace_site_complete_registration';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $account = $this->pendingRegistration->getAccount();
    if ($account === NULL) {
      $this->messenger()->addError($this->t('Your registration could not be completed. Please sign in again.'));
      $form_state->setRedirect('<front>');
      return $form;
    }

    // The OpenID Connect module prints its own error and pending messages right
    // before it redirects here. They are replaced by this form and its own
    // pending message.
    $this->removeOpenIdConnectMessages();

    $first_name = trim($account->get('field_first_name')->getString());
    $last_name = trim($account->get('field_last_name')->getString());

    $form['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->fieldLabel($account, 'field_first_name', $this->t('First name')),
      '#default_value' => $first_name,
      '#required' => TRUE,
      '#maxlength' => 255,
      '#attributes' => [
        'autocomplete' => 'given-name',
      ],
    ];
    if ($first_name === '') {
      $form['first_name']['#attributes']['autofocus'] = 'autofocus';
    }

    $form['last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->fieldLabel($account, 'field_last_name', $this->t('Last name')),
      '#default_value' => $last_name,
      '#required' => TRUE,
      '#maxlength' => 255,
      '#attributes' => [
        'autocomplete' => 'family-name',
      ],
    ];
    if ($first_name !== '' && $last_name === '') {
      $form['last_name']['#attributes']['autofocus'] = 'autofocus';
    }

    $form['actions'] = [
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Continue'),
      ],
    ];

    return $form;
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
    $account = $this->pendingRegistration->getAccount();
    if ($account === NULL) {
      $this->messenger()->addError($this->t('Your registration could not be completed. Please sign in again.'));
      $form_state->setRedirect('<front>');
      return;
    }

    // The user name is regenerated from the names by the username hooks.
    $account->set('field_first_name', self::submittedValue($form_state, 'first_name'));
    $account->set('field_last_name', self::submittedValue($form_state, 'last_name'));
    $account->save();
    $this->pendingRegistration->clear();
    // A member who sent no name on the first login gets the mail only now, so
    // it announces the pending approval with the completed names.
    $this->pendingApprovalMailer->sendOnce($account);

    $this->messenger()->addStatus($this->t('Thank you for applying for an account. Your account is currently pending approval by the site administrator.<br />In the meantime, a welcome message with further instructions has been sent to your email address.'));
    $form_state->setRedirect('<front>');
  }

  /**
   * Returns the label of an account name field.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account.
   * @param string $field_name
   *   The machine name of the field.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $fallback
   *   The label to use when the field has none.
   *
   * @return string|\Drupal\Core\StringTranslation\TranslatableMarkup
   *   The field label.
   */
  private function fieldLabel(UserInterface $account, string $field_name, TranslatableMarkup $fallback): string|TranslatableMarkup {
    if (!$account->hasField($field_name)) {
      return $fallback;
    }

    return $account->getFieldDefinition($field_name)?->getLabel() ?? $fallback;
  }

  /**
   * Returns a trimmed string value of the submitted form.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $key
   *   The key of the submitted value.
   *
   * @return string
   *   The trimmed value, or an empty string.
   */
  private static function submittedValue(FormStateInterface $form_state, string $key): string {
    $value = $form_state->getValue($key);

    return is_string($value) ? trim($value) : '';
  }

  /**
   * Removes the OpenID Connect messages of the login that led to this form.
   *
   * The messages are matched on their translated templates because the
   * rendered messages contain the provider label and the user name. All other
   * messages are kept.
   */
  private function removeOpenIdConnectMessages(): void {
    $messenger = $this->messenger();

    // Both errors embed a dynamic value, so compare the static prefix and
    // suffix around it.
    $error_templates = [
      (string) $this->t('The username %name has not been activated or is blocked.'),
      (string) $this->t('Logging in with @provider could not be completed due to an error.'),
    ];
    $kept_errors = [];
    foreach ($messenger->messagesByType(MessengerInterface::TYPE_ERROR) as $message) {
      if (!self::matchesAnyTemplate((string) $message, $error_templates)) {
        $kept_errors[] = $message;
      }
    }
    $messenger->deleteByType(MessengerInterface::TYPE_ERROR);
    foreach ($kept_errors as $message) {
      $messenger->addError($message);
    }

    // The OpenID Connect pending message is a static string.
    $pending = (string) $this->t('Thank you for applying for an account. Your account is currently pending approval by the site administrator.');
    $kept_status = [];
    foreach ($messenger->messagesByType(MessengerInterface::TYPE_STATUS) as $message) {
      if ((string) $message !== $pending) {
        $kept_status[] = $message;
      }
    }
    $messenger->deleteByType(MessengerInterface::TYPE_STATUS);
    foreach ($kept_status as $message) {
      $messenger->addStatus($message);
    }
  }

  /**
   * Checks a message against translated templates with placeholders.
   *
   * @param string $message
   *   The rendered message.
   * @param string[] $templates
   *   The translated templates, for example "The username %name ...".
   *
   * @return bool
   *   TRUE when the message matches one of the templates.
   */
  private static function matchesAnyTemplate(string $message, array $templates): bool {
    foreach ($templates as $template) {
      $parts = preg_split('/%[a-z_]+|@[a-z_]+/i', $template);
      if ($parts === FALSE || count($parts) < 2) {
        if ($message === $template) {
          return TRUE;
        }
        continue;
      }
      $pattern = '/^' . implode('.+', array_map(
        static fn (string $part): string => preg_quote($part, '/'),
        $parts,
      )) . '$/u';
      if (preg_match($pattern, $message) === 1) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
