<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_site\Kernel;

use Drupal\contact\Entity\ContactForm;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\workspace_site\Plugin\Block\ContactFormBlock;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the contact form block.
 */
#[CoversClass(ContactFormBlock::class)]
#[Group('workspace_site')]
#[RunTestsInSeparateProcesses]
final class ContactFormBlockTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'contact',
    'comment',
    'workspace_site',
  ];

  /**
   * The block plugin manager.
   */
  private BlockManagerInterface $blockManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('contact_message');
    $this->installConfig(['contact']);

    ContactForm::create([
      'id' => 'kontakt',
      'label' => 'Kontakt',
      'recipients' => ['info@example.com'],
      'message' => 'Thank you for your message.',
    ])->save();

    $this->blockManager = $this->container->get(BlockManagerInterface::class);
  }

  /**
   * Tests that the default contact form is configured.
   */
  public function testDefaultConfiguration(): void {
    $block = $this->blockManager->createInstance('salsa_contact_form');
    self::assertSame('kontakt', $block->getConfiguration()['contact_form']);
  }

  /**
   * Tests access to the block.
   */
  public function testBlockAccess(): void {
    $block = $this->blockManager->createInstance('salsa_contact_form', ['contact_form' => 'kontakt']);

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->with('access site-wide contact form')
      ->willReturn(FALSE);
    $access = $block->access($account, TRUE);
    self::assertFalse($access->isAllowed());
    self::assertTrue($access->isNeutral());

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->with('access site-wide contact form')
      ->willReturn(TRUE);
    $access = $block->access($account, TRUE);
    self::assertTrue($access->isAllowed());
    self::assertContains('config:contact.form.kontakt', $access->getCacheTags());

    // A disabled contact form must not be rendered.
    $contact_form = ContactForm::load('kontakt');
    \assert($contact_form instanceof ContactForm);
    $contact_form->disable()->save();
    $access = $block->access($account, TRUE);
    self::assertFalse($access->isAllowed());
  }

  /**
   * Tests that a missing contact form denies access and renders nothing.
   */
  public function testMissingContactForm(): void {
    $block = $this->blockManager->createInstance('salsa_contact_form', ['contact_form' => 'missing']);

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(TRUE);
    $access = $block->access($account, TRUE);
    self::assertTrue($access->isForbidden());

    $build = $block->build();
    self::assertArrayNotHasKey('#form_id', $build);
    self::assertContains('config:contact_form_list', $build['#cache']['tags']);
  }

  /**
   * Tests that the contact form is built.
   */
  public function testBuild(): void {
    $block = $this->blockManager->createInstance('salsa_contact_form', ['contact_form' => 'kontakt']);
    $build = $block->build();

    self::assertSame('contact_message_kontakt_form', $build['#form_id']);
    self::assertContains('config:contact.form.kontakt', $build['#cache']['tags']);
  }

}
