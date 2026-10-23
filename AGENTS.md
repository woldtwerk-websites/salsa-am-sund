# Agent guidance for this Drupal site

This codebase is a Composer-managed Drupal site.

## Detect the command runner first

Do not assume DDEV is installed. Before running anything, work out how this codebase invokes Composer
and Drush, from the project root:

1. **DDEV (preferred)** — a `.ddev/` directory exists *and* `ddev` is on `PATH` (`command -v ddev`).
   Use `ddev composer` and `ddev drush`, as described below.
2. **Local PHP + Composer** — no usable `ddev`, but `composer` is on `PATH` (`command -v composer`).
   Run `composer install` if `vendor/` doesn't exist yet. Use plain `composer`, and check
   whether `vendor/bin/drush` exists (some packages of this project ship without Drush), if it does, use that (not a global `drush`); if it doesn't, skip the Drush workflow below.

State which one you picked before running commands. If neither applies, stop and report it rather
than guessing at a command that will fail. In the workflow sections below, substitute the Composer
and Drush commands for the option you resolved.

## Local environment (DDEV)

Run commands from the project root:

- Start or restart the local environment with `ddev start`, `ddev restart`, and `ddev stop`.
- Install PHP dependencies with `ddev composer install`.
- Open the site with `ddev launch`.
- Run Drush commands with `ddev drush <command>` such as `status`, `user:login`,  `cache:rebuild`, and `updatedb`.

DDEV project config lives in `.ddev/config.yaml`. Use `.ddev/config.local.yaml` for machine-specific overrides.

## Common Drupal workflows

- Add a module with `ddev composer require drupal/<project>`, then  `ddev drush pm:enable --yes <module_machine_name>`, then `ddev drush cache:rebuild`.
- Apply database updates after code changes with `ddev drush updatedb --yes`.
- Import repository configuration into the site with `ddev drush config:import --yes`.
- Export site configuration back to the repo with `ddev drush config:export --yes`.

## Guardrails

- Do not commit secrets or machine-local overrides such as `.env`, `settings.local.php`, or `.ddev/config.local.yaml`.
- Do not commit `vendor/` or uploaded files under `web/sites/*/files`.
- Do not edit Drupal core or contributed projects in place.
- Put custom code in `web/modules/custom` and `web/themes/custom`.

## References

- https://docs.ddev.com/en/stable/
- https://www.drupal.org/docs/administering-a-drupal-site/configuration-management/workflow-using-drush

## Performance

Always keep in mind to write efficient code that scales nicely. Avoid unnecessary queries, and make
sure to use caching where possible. Inform me if I suggest any poorly performing code
or data structure and propose a better solution.

## Testing

Only test what matters: the custom logic in this repo.

- Don't test Drupal core, contributed modules or other third-party code.
- Don't write UI tests (no browser, rendering or markup tests).

## Claude Code: delegate coding to opencode

This section is for Claude Code only. opencode reads this file too: if you are
opencode, ignore this section — you are the one who writes the code.

Claude does not write code in this repo itself. Claude plans and reviews;
opencode writes the code. Every coding task goes to opencode as a plan of
concrete todos — files, functions, behaviour, tests to add — with the research
and design decisions already made:

```
opencode run --auto -m 'opencode-go/deepseek-v4.1-flash#high' --session ses_<task> --title "<task>" "<plan>" </dev/null
```

- Name the session (`--session` creates it), so the user can watch the run
  live with `opencode -s ses_<task>`.
- Put the rules from this file that apply to the task into the prompt — ddev,
  pnpm scripts, UI/logic split with vitest, URL state, never touch `.data/`,
  `touch nuxt/nuxt.config.ts` after layer edits.
- Run several opencode agents in parallel when tasks are independent, each in
  its own git worktree under `.worktrees/<task>` (inside the repo, so ddev
  sees it) and its own session. Tasks that touch `pnpm-workspace.yaml`, the
  lockfile or `.ddev/` don't run alongside others. Review and merge the
  results one at a time.
- Review the diff (correctness, security, this file's rules) and run
  `ddev exec pnpm run lint`, `ddev exec pnpm run typecheck` and the tests.
- Send findings back as concrete todos with
  `opencode run --auto -m 'opencode-go/deepseek-v4.1-flash#high' --session ses_<task> "<findings>"` until the review
  passes.
- `--auto` approves every permission opencode asks for, so commits, deploys,
  migrations and deletions still need the user's go-ahead first.

## Skills

When working in web always use these skills:
- drupal
- drupal-expert
- drupal-security
- drupal-testing

## Commit

Always commit in english
