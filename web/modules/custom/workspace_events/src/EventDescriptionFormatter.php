<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use Drupal\text\TextSummary;

/**
 * Formats event body text for blocks and the iCal feed.
 */
final class EventDescriptionFormatter {

  /**
   * Constructs an EventDescriptionFormatter.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\text\TextSummary $textSummary
   *   The text summary generator.
   */
  public function __construct(
    private readonly RendererInterface $renderer,
    private readonly TextSummary $textSummary,
  ) {}

  /**
   * Returns the event description as filtered HTML.
   *
   * Prefers the body summary over the body value, trims the text to a sensible
   * length and runs it through the body's text format.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   * @param int $length
   *   The maximum length of the description.
   *
   * @return \Drupal\Component\Render\MarkupInterface|null
   *   The filtered description, or NULL when the event has no body text.
   */
  public function toHtml(NodeInterface $node, int $length = 200): ?MarkupInterface {
    $body = $this->getBody($node);
    if ($body === NULL) {
      return NULL;
    }
    $text = $body['summary'] !== '' ? $body['summary'] : $body['value'];
    if (trim($text) === '') {
      return NULL;
    }

    $format = $body['format'] !== '' ? $body['format'] : NULL;
    $trimmed = (string) $this->textSummary->generate($text, $format, $length);
    if (trim($trimmed) === '') {
      // The format does not exist: keep the text but render it as plain text.
      $plain = trim(strip_tags($text));
      if ($plain === '') {
        return NULL;
      }
      $plain = Unicode::truncate($plain, $length, TRUE, TRUE);
      $markup = Markup::create('<p>' . nl2br(Html::escape($plain)) . '</p>');
      \assert($markup instanceof MarkupInterface);
      return $markup;
    }

    // Truncation may have cut an HTML element in half.
    $build = [
      '#type' => 'processed_text',
      '#text' => Html::normalize($trimmed),
      '#format' => $format,
    ];
    return $this->renderer->renderInIsolation($build);
  }

  /**
   * Returns the event description as plain text.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   * @param int $length
   *   The maximum length of the description.
   *
   * @return string
   *   The plain text description, without the text format name.
   */
  public function toPlainText(NodeInterface $node, int $length = 400): string {
    $body = $this->getBody($node);
    if ($body === NULL) {
      return '';
    }
    $text = $body['summary'] !== '' ? $body['summary'] : $body['value'];
    $format = $body['format'] !== '' ? $body['format'] : NULL;
    $text = (string) $this->textSummary->generate($text, $format, $length);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
  }

  /**
   * Returns the body summary, value and format of an event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return array{summary: string, value: string, format: string}|null
   *   The body text, or NULL when the node has no body value.
   */
  private function getBody(NodeInterface $node): ?array {
    if (!$node->hasField('body') || $node->get('body')->isEmpty()) {
      return NULL;
    }
    $item = $node->get('body')->first();
    if ($item === NULL) {
      return NULL;
    }
    return [
      'summary' => trim($item->get('summary')->getString()),
      'value' => $item->get('value')->getString(),
      'format' => $item->get('format')->getString(),
    ];
  }

}
