# Too Many Coins Agent Rules

## Repo model
- source/dev is the working lane
- too-many-coins-game is public sandbox
- too-many-coins-live is public live

## Deployment model
- Dokploy app `too-many-coins-test` deploys only from test repo
- live deployment deploys only from live repo

## Release discipline
- all feature work starts in source/dev
- push approved builds to test first
- only promote approved tested commits to live

## Notes
- keep deployment changes minimal
- preserve init/db behavior unless explicitly fixing it
- do not mix sandbox and live env values