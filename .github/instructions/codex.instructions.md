You are working on the Too Many Coins project.

Follow these standing repo and release rules at all times unless I explicitly override them.

## Repo lanes
- Source/dev repo is the main working repo.
- Public test repo: TheRealTwizzy/too-many-coins-game
- Public live repo: TheRealTwizzy/too-many-coins-live

## Deployment intent
- The test repo is the public sandbox/beta lane.
- The live repo is stable production only.

## Promotion flow
- source/dev -> test -> live
- Never push to live as part of normal feature work.
- Only promote to live from a tested, approved commit.

## Git discipline
- Do work on feature branches first.
- Keep commits clean and logical.
- Use conventional commit prefixes:
  - feat:
  - fix:
  - balance:
  - chore:
  - docs:

## Release/tag rules
- Beta/test tags look like: v0.1.0-beta.1
- Live tags look like: v0.1.0

## Patch notes format
When asked to prepare release notes, organize them as:
- Added
- Changed
- Fixed
- Known Issues (beta only when applicable)

## Environment separation
Never assume test and live share:
- DB
- secrets
- tick secret
- worker
- domain
- storage
- Dokploy app

Use placeholders if exact values are unknown.

## Safety rules
- Never rewrite production history unless explicitly instructed.
- Never mix test config into live config.
- Never remove working deployment behavior without replacing it safely.
- If a step is risky, stop at a reviewable state and explain the manual step.

## Output behavior
- Inspect first before making broad edits.
- Prefer minimal, robust changes.
- Show what files changed and why.
- Provide exact Git commands after making changes.