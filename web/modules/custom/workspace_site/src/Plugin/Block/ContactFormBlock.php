<?php

declare(strict_types=1);

namespace Drupal\workspace_site\Plugin\Block;

use Drupal\contact\ContactFormInterface;
use Drupal\contact\Entity\Message;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a block that renders a site-wide contact form.
 *
 * @see \Drupal\contact\Entity\Message
 */
#[Block(
  id: 'salsa_contact_form',
  admin_label: new TranslatableMarkup('Contact form'),
  category: new TranslatableMarkup('Salsa am Sund'),
)]
final class ContactFormBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The contact form shown when the block has no explicit configuration.
   */
  public const string DEFAULT_CONTACT_FORM = 'kontakt';

  /**
   * The ID of the personal contact form, which is not site-wide.
   */
  private const string PERSONAL_FORM_ID = 'personal';

  /**
   * Constructs a ContactFormBlock object.
   *
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param array<string, mixed> $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFormBuilderInterface $entityFormBuilder
   *   The entity form builder.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFormBuilderInterface $entityFormBuilder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param array<string, mixed> $plugin_definition
   *   The plugin implementation definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $entity_type_manager = $container->get(EntityTypeManagerInterface::class);
    \assert($entity_type_manager instanceof EntityTypeManagerInterface);
    $entity_form_builder = $container->get(EntityFormBuilderInterface::class);
    \assert($entity_form_builder instanceof EntityFormBuilderInterface);

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $entity_type_manager,
      $entity_form_builder,
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<mixed>
   *   The default configuration.
   */
  public function defaultConfiguration(): array {
    return [
      'contact_form' => self::DEFAULT_CONTACT_FORM,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The block configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<mixed>
   *   The block configuration form.
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $options = [];
    $contact_forms = $this->entityTypeManager->getStorage('contact_form')->loadMultiple();
    foreach ($contact_forms as $id => $contact_form) {
      \assert($contact_form instanceof ContactFormInterface);
      // The personal contact form is not a site-wide contact form.
      if ($id === self::PERSONAL_FORM_ID) {
        continue;
      }
      $options[$id] = (string) $contact_form->label();
    }

    $form['contact_form'] = [
      '#type' => 'select',
      '#title' => $this->t('Contact form'),
      '#description' => $this->t('The contact form to display.'),
      '#options' => $options,
      '#default_value' => $this->getConfiguration()['contact_form'] ?? self::DEFAULT_CONTACT_FORM,
      '#empty_option' => $this->t('- Select -'),
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The block configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $contact_form = $form_state->getValue('contact_form');
    $this->configuration['contact_form'] = is_string($contact_form) ? $contact_form : '';
  }

  /**
   * {@inheritdoc}
   *
   * @return array<mixed>
   *   The render array of the contact form.
   */
  public function build(): array {
    $contact_form = $this->loadContactForm();
    $build = [];
    if ($contact_form === NULL) {
      // Depend on the contact form list so that adding the configured form
      // invalidates this (empty) render array.
      $cacheability = new CacheableMetadata();
      $cacheability->addCacheTags($this->contactFormListCacheTags());
      $cacheability->applyTo($build);
      return $build;
    }

    $message = Message::create(['contact_form' => $contact_form->id()]);
    $build = $this->entityFormBuilder->getForm($message);
    $cacheability = CacheableMetadata::createFromRenderArray($build);
    $cacheability->addCacheableDependency($contact_form);
    $cacheability->applyTo($build);

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResultInterface {
    $contact_form = $this->loadContactForm();
    if ($contact_form === NULL) {
      return AccessResult::forbidden('The configured contact form does not exist.')
        ->addCacheTags($this->contactFormListCacheTags());
    }

    return AccessResult::allowedIfHasPermission($account, 'access site-wide contact form')
      ->andIf(AccessResult::allowedIf($contact_form->status()))
      ->addCacheableDependency($contact_form);
  }

  /**
   * Loads the configured contact form, if it exists.
   */
  private function loadContactForm(): ?ContactFormInterface {
    $id = $this->getConfiguration()['contact_form'] ?? NULL;
    if (!is_string($id) || $id === '') {
      return NULL;
    }
    $contact_form = $this->entityTypeManager->getStorage('contact_form')->load($id);
    return $contact_form instanceof ContactFormInterface ? $contact_form : NULL;
  }

  /**
   * Returns the cache tags of the contact form list.
   *
   * @return string[]
   *   The list cache tags of the contact_form entity type.
   */
  private function contactFormListCacheTags(): array {
    return $this->entityTypeManager->getDefinition('contact_form')->getListCacheTags();
  }

}
