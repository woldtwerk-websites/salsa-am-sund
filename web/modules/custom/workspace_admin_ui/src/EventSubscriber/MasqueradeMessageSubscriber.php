<?php

declare(strict_types=1);

namespace Drupal\workspace_admin_ui\EventSubscriber;

use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Drops the status message masquerade shows after a switch.
 *
 * "You are now masquerading as @user" says what the toolbar says anyway — the
 * Unmasquerade link is either there or it is not.
 *
 * Taken off the session at response time rather than by overriding
 * `SwitchController`, which would mean a route subscriber and a subclass to
 * suppress two lines. Only the status type goes: the same controller reports
 * a refused or failed switch as an error, and that has to survive.
 */
class MasqueradeMessageSubscriber implements EventSubscriberInterface {

  /**
   * The routes whose status messages are dropped.
   *
   * @var list<string>
   */
  private const ROUTES = [
    'entity.user.masquerade',
    'masquerade.unmasquerade',
  ];

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(
    protected MessengerInterface $messenger,
  ) {
  }

  /**
   * Clears the status messages a masquerade switch queued.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onResponse(ResponseEvent $event): void {
    // Nothing but the switch controller runs on these two routes, so there is
    // no other status message in the queue to take with it.
    if (!$event->isMainRequest()) {
      return;
    }
    if (!in_array($event->getRequest()->attributes->get('_route'), self::ROUTES, TRUE)) {
      return;
    }

    $this->messenger->deleteByType(MessengerInterface::TYPE_STATUS);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => [['onResponse']]];
  }

}
