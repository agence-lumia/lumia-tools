<!-- PR title: Conventional Commits in English, e.g. `fix(security): …`, `feat(media): …`, `docs: …` -->

Closes #

## What

<!-- What the PR changes, in two or three sentences. -->

## Why

<!-- The problem found or the need. If a behavior broke, say what broke and why the chosen solution is the right one. -->

## How to test

<!-- Verification steps on the local environment, screens concerned, edge cases checked. -->

## Checklist

- [ ] Branch created from `dev`, PR to `dev`
- [ ] No manual version bump
- [ ] `composer check` passes (no finding, no baseline recreated)
- [ ] New PHP files: `defined( 'ABSPATH' ) || exit;` guard
- [ ] New or changed strings: English source with the `lumia-tools` text domain, `.pot` / `.po` / `.mo` updated (`composer i18n:pot`, `i18n:mo`, `i18n:check`)
- [ ] Icons: official Lucide SVGs only
- [ ] `docs/` updated if a pitfall or a decision came out of this PR
