# Deploy Too Many Coins on Dokploy + Hostinger MySQL

This guide deploys the app on a Dokploy-managed Ubuntu 24.04 VPS, using:
- Dockerfile build for the app container
- PHP 8.3 + Apache (serving HTML/CSS/JavaScript frontend and PHP API)
- A dedicated internal tick worker service from the same image
- External MySQL database hosted on Hostinger

## 1. Prerequisites

- Dokploy installed and reachable on your VPS
- Hostinger MySQL database created
- Hostinger DB remote access enabled for your VPS public IP
- Repository connected to Dokploy (GitHub/GitLab)

## 2. Hostinger Database Setup

In Hostinger hPanel:
1. Create database, user, and password.
2. Enable remote MySQL access.
3. Add your VPS public IP to allowed hosts.
4. Note these values:
   - `DB_HOST`
   - `DB_PORT` (usually `3306`)
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASS`

## 3. Create App in Dokploy

1. Create a new Application in Dokploy.
2. Source: select your Git repository and branch.
3. Build method: Dockerfile.
4. Dockerfile path: `Dockerfile`.
5. Exposed container port: `80`.
6. Public domain: set your domain/subdomain.

Create a second Dokploy service in the same project for ticks:

1. Service type: Application (Dockerfile) using the same repo/branch.
2. Dockerfile path: `Dockerfile`.
3. Do not expose a public port.
4. Set start command/entrypoint override to:

```bash
/app/docker/worker-entrypoint.sh
```

5. Replicas: `1`.

## 4. Environment Variables in Dokploy

Set variables per service as below.

Web service env:

- `DB_HOST=<hostinger mysql host>`
- `DB_PORT=3306`
- `DB_NAME=<database name>`
- `DB_USER=<database user>`
- `DB_PASS=<database password>`
- `TMC_TIME_SCALE=1`
- `TMC_TICK_REAL_SECONDS=5`
- `TMC_TICK_ON_REQUEST=false`
- `TMC_INIT_SECRET=<strong-random-secret>`

Worker service env:

- `DB_HOST=<hostinger mysql host>`
- `DB_PORT=3306`
- `DB_NAME=<database name>`
- `DB_USER=<database user>`
- `DB_PASS=<database password>`
- `TMC_TIME_SCALE=1`
- `TMC_TICK_REAL_SECONDS=5`
- `TMC_TICK_ON_REQUEST=false`
- `TMC_WORKER_INTERVAL_SECONDS=5`
- `TMC_WORKER_START_DELAY_SECONDS=2`

Optional worker tuning:

- `TMC_WORKER_ERROR_BACKOFF_SECONDS=2`

Optional fallback-only scheduler secret:

- `TMC_TICK_SECRET=<strong-random-secret>`

Optional:

- `TZ=UTC`

Use identical DB values in both services. The worker service uses `TMC_WORKER_*` values and `TMC_TICK_REAL_SECONDS` to process ticks internally.

`TMC_TICK_ON_REQUEST=false` is recommended in production when running the worker, so tick progression does not depend on user API traffic.

## 5. First Deploy

Run deploy in Dokploy. After it is healthy, initialize schema/data once.

### Option A: Run in Dokploy terminal (recommended)

```bash
php /app/init_db.php
```

### Option B: HTTP init endpoint (only if needed)

```text
https://your-domain/api/index.php?action=init_db&secret=YOUR_TMC_INIT_SECRET
```

Do not leave weak init secrets. Rotate or remove access after initialization.

## 6. Health Checks

Use one of these paths in Dokploy:
- `/`
- `/api/index.php?action=game_state`

If your app requires auth for some endpoints, use `/` for health checks.

## 7. DNS and SSL

1. Point your domain A record to the VPS IP.
2. Configure SSL in Dokploy for your domain.
3. Verify HTTPS and API reachability:
   - `https://your-domain/`
   - `https://your-domain/api/index.php?action=game_state`

## 8. Dedicated Tick Worker (Recommended)

The worker service runs:

```bash
/app/docker/worker-entrypoint.sh
```

and internally executes:

```bash
php /app/worker/tick_worker.php
```

This avoids public tick curl traffic and supports sub-minute intervals.

Recommended production values:

- `TMC_TICK_REAL_SECONDS=5`
- `TMC_WORKER_INTERVAL_SECONDS=5`
- `TMC_TICK_ON_REQUEST=false`

Validation checks:

1. Web service is healthy at `/` and `/api/index.php?action=game_state`.
2. Worker service logs show startup line: `[tick-worker] starting ...`.
3. `server_state.last_tick_processed_at` advances every few seconds.

Fallback only if worker service cannot be used:

```text
POST https://your-domain/api/index.php?action=tick
Header: X-Tick-Secret: <TMC_TICK_SECRET>
```

## 9. Troubleshooting

- `Database connection failed`:
  - Verify Hostinger allows your VPS IP.
  - Confirm `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`.
- Static frontend works but API fails:
  - Check Dokploy app logs for PHP errors.
  - Confirm env vars are present in runtime, not only build time.
- Init returns forbidden:
  - Set `TMC_INIT_SECRET` and use it in the init URL, or run CLI init.

- Worker not progressing ticks:
  - Confirm worker service start command is `/app/docker/worker-entrypoint.sh`.
  - Confirm worker replicas are `1`.
  - Check worker logs for `[tick-worker] error` lines.
  - Confirm DB env vars are present on worker service, not only web service.

- Tick endpoint returns forbidden/not configured:
  - This matters only for fallback HTTP scheduler mode.
  - Ensure `X-Tick-Secret` matches `TMC_TICK_SECRET`.

## 10. Stack Clarification

This deployment uses:
- Dockerfile
- PHP
- MySQL
- HTML/CSS
- JavaScript (frontend client)

If by "JAVA" you intended JVM Java, that is not used by this codebase.
