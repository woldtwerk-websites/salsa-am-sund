---
name: drupal
description: Additional instructions for working with drupal.
---

## Development
- always use oop hooks instead of hooks in .module and .theme

## Frontend Development

- Always prefer using sdc over drupal templates.
- use cva with ui prop and tailwind merge.

## Class Variance Authority

Components should expose a ui prop.

E.g.:

```twig
{% set ui = {
  root: html_cva(
    base: [
      'py-4',
      ui.root,
    ],
  ),
  summary: html_cva(
    base: [
      'flex',
      ui.summary,
    ],
  ),
  content: html_cva(
    base: [
      ui.content,
    ],
  ),
} %}

<details {{ attributes.addClass(tw_merge(ui.root.apply({}))) }}>
  <summary class="{{ html_classes(tw_merge(ui.summary.apply({}))) }}>
    <h3>
      {{- summary -}}
    </h3>
    {{ icon('foo', 'expand', { size: 48, class: [
      'shrink-0',
    ]|join(' ') }) }}
    {{ icon('foo', 'minus', { size: 48, class: [
      'hidden',
      'shrink-0',
    ]|join(' ') }) }}
  </summary>
  <div class="{{ html_classes(tw_merge(ui.content.apply({}))) }}">
    {{- content -}}
  </div>
</details>

```
