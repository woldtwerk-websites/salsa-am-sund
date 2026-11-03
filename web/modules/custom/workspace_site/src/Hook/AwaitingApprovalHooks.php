<?php

declare(strict_types=1);

namespace Drupal\workspace_site\Hook;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\UserInterface;

/**
 * Marks self-registered accounts that wait for an administrator's approval.
 *
 * Blocked alone cannot tell a pending self-registration from an account an
 * administrator created blocked or blocked later, so the account carries an
 * explicit marker. Only a self-registration sets it; an approval clears it and
 * blocking an approved account does not set it again.
 */
final class AwaitingApprovalHooks {

  /**
   * The name of the marker field on the user entity.
   */
  public const FIELD_NAME = 'awaiting_approval';

  /**
   * Constructs the awaiting approval hooks.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Implements hook_entity_base_field_info().
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   *
   * @return array<string, \Drupal\Core\Field\BaseFieldDefinition>
   *   The base fields, keyed by field name.
   */
  #[Hook('entity_base_field_info')]
  public function entityBaseFieldInfo(EntityTypeInterface $entity_type): array {
    if ($entity_type->id() !== 'user') {
      return [];
    }

    return [self::FIELD_NAME => self::fieldDefinition()];
  }

  /**
   * Returns the definition of the marker field.
   *
   * The update hook reuses the definition, so the field is defined only once.
   *
   * @return \Drupal\Core\Field\BaseFieldDefinition
   *   The field definition.
   */
  public static function fieldDefinition(): BaseFieldDefinition {
    return BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Awaiting approval'))
      ->setDescription(new TranslatableMarkup('Whether the account registered itself and waits for an administrator to approve it.'))
      ->setDefaultValue(FALSE);
  }

  /**
   * Implements hook_user_presave().
   *
   * @param \Drupal\user\UserInterface $user
   *   The user being saved.
   */
  #[Hook('user_presave')]
  public function presave(UserInterface $user): void {
    if (!$user->hasField(self::FIELD_NAME)) {
      return;
    }

    // A blocked account created by an anonymous visitor registered itself,
    // whether through the register form or an OpenID Connect sign-up.
    if ($user->isNew() && $user->isBlocked() && $this->currentUser->isAnonymous()) {
      $user->set(self::FIELD_NAME, TRUE);
      return;
    }

    // An active account has been approved. Blocking an active account later
    // does not make it await approval again.
    if (!$user->isBlocked()) {
      $user->set(self::FIELD_NAME, FALSE);
    }
  }

}
