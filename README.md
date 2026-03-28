# Too Many Coins

A deterministic economic competition game where every coin tells a story of sacrifice, strategy, and timing. Built with HTML, CSS, PHP, MySQL, and JavaScript.

## Game Overview

Too Many Coins is a season-based multiplayer economic strategy game. Players join 28-day competitive seasons, earn Coins through Universal Basic Income (UBI), convert them into Stars to climb the leaderboard, collect Sigils through random drops and vault purchases, activate Boosts to increase their income, and ultimately decide when to Lock-In and convert their Seasonal Stars to permanent Global Stars.

## Features

| Feature | Description |
|---------|-------------|
| **Universal Basic Income** | Dynamic UBI system with activity bonuses, inflation curves, and hoarding penalties |
| **Star Purchasing** | Convert Coins to Seasonal Stars at dynamic prices based on total coin supply |
| **5-Tier Sigil System** | Random drops (1/50,000 per tick) with pity timer, plus vault purchases |
| **Boost Activation** | Consume Sigils to activate temporary UBI modifiers (self or season-wide) |
| **Lock-In Mechanism** | Exit early to convert Seasonal Stars to Global Stars |
| **Player Trading** | Trade Coins and Sigils with other season participants |
| **Season Leaderboard** | Ranked by Seasonal Stars with placement bonuses |
| **Global Leaderboard** | Ranked by Global Stars earned across all seasons |
| **Cosmetics Shop** | 24 cosmetic items across 5 categories, purchasable with Global Stars |
| **Chat System** | Global and per-season chat channels |
| **Idle Detection** | Activity tracking with idle acknowledgment system |

## Boost Catalog

| Boost | Tier | Effect | Duration | Scope |
|-------|------|--------|----------|-------|
| Trickle | I | +10% UBI | 1 hour | Self |
| Surge | II | +15% UBI | 3 hours | Self |
| Flow | III | +25% UBI | 6 hours | Self |
| Tide | IV | +50% UBI | 12 hours | Self |
| Age | V | +100% UBI | 24 hours | Self |

Guaranteed floor policy (hybrid scaling):

- +1 whole Coin per tick per 10% effective boost modifier
- Applied after percent boost math and before fixed-point mint split
- No cap by default (`BOOST_GUARANTEED_FLOOR_CAP_COINS = 0`)

## Sigil Drop System

- **Base Rate**: 1 in 750 per eligible tick (1-minute ticks)
- **Eligibility**: Must be Online + Participating + Active (not Idle)
- **Pity Timer**: Guaranteed Tier I drop after 2,000 eligible ticks with no drop
- **Throttle**: Maximum 8 drops per rolling 1,440-tick window
- **Tier Odds**: T1 70%, T2 20%, T3 8%, T4 1.5%, T5 0.5%

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Frontend | HTML5, CSS3, JavaScript (Vanilla SPA) |
| Backend | PHP 8.x (REST API) |
| Database | MySQL 8.x |
| Architecture | Tick-based deterministic game engine |

## Project Structure

```
too-many-coins/
├── public/                      # Web-accessible files
│   ├── index.html              # Main HTML page (SPA)
│   ├── css/style.css           # Complete stylesheet (dark theme, gold accents)
│   ├── js/app.js               # Game client JavaScript
│   └── wiki/                   # Wiki pages (served at /wiki)
│       ├── index.html
│       ├── getting-started/
│       ├── game-systems/
│       ├── deployment/
│       └── assets/wiki.css
├── api/
│   └── index.php               # API router (all endpoints)
├── includes/
│   ├── config.php              # Configuration constants
│   ├── database.php            # Database connection class
│   ├── game_time.php           # Game time and season management
│   ├── economy.php             # UBI, pricing, trading, sigil drops
│   ├── tick_engine.php         # Tick processing engine
│   ├── actions.php             # Player action handlers
│   └── auth.php                # Authentication helpers
├── schema.sql                  # Database schema
├── seed_data.sql               # Initial data (cosmetics)
├── migration_boosts_drops.sql  # Boost and drop tables
├── migration_boost_duration_hotfix.sql # One-time live DB boost duration/description fixes
├── router.php                  # PHP dev server router
├── setup.sh                    # Production deployment script
├── tools/import-wiki-zip.ps1   # Import external wiki ZIP into local reference workspace
└── README.md                   # This file
```

## Automatic DB Migrations

Runtime migration application is enabled by default and runs on redeploy/startup:

- Any repo-root file matching `migration_*.sql` is auto-applied once.
- Applied files are tracked in `schema_migrations` with filename + checksum.
- Files ending with `_optional.sql` remain manual-only.
- `migration_boosts_drops.sql` remains init/setup-only bootstrap and is excluded from runtime auto-apply.

Guidelines:

- Add new DB changes as new `migration_*.sql` files (do not edit already-applied files).
- Keep auto-applied migrations idempotent where possible.

Disable auto-migrations only if needed:

- `TMC_AUTO_SQL_MIGRATIONS=false`

Backward-compatibility alias still works:

- `TMC_AUTO_SQL_HOTFIX=false`

## Wiki (In-Repo, Same Domain)

The project now includes an isolated wiki surface served under:

- `/wiki/`

Routing behavior is configured so `/wiki/*` does not fall into the SPA fallback:

- Dev server: `router.php`
- Nginx container: `docker/nginx.conf`
- Apache container: `docker/apache-vhost.conf`

This keeps game navigation and API routes unchanged while allowing static wiki deep links.

Current wiki routes:

- `/wiki/`
- `/wiki/getting-started/`
- `/wiki/game-systems/`
- `/wiki/competition/`
- `/wiki/social/`
- `/wiki/strategy/`
- `/wiki/search/`

Migration tracking document:

- `WIKI_MIGRATION_STATUS.md`

Implementation notes:

- Full chapter/section content is rendered from `public/wiki/assets/wiki-data.js`.
- The shared renderer `public/wiki/assets/wiki-render.js` handles category page rendering and client-side search.

## External ZIP Migration Workflow

1. Place your external wiki ZIP anywhere accessible on your machine.
2. Run the import script from the repo root in PowerShell:

```powershell
.\tools\import-wiki-zip.ps1 -ZipPath "C:\path\to\your-existing-wiki.zip"
```

3. The ZIP is extracted into `wiki_source/imported/<timestamp>/` (git-ignored).
4. Use extracted pages/assets as reference source, then copy curated content into `public/wiki/`.
5. Validate routes:

```bash
curl -I http://localhost:8080/wiki/
curl -I http://localhost:8080/wiki/getting-started/
curl -I http://localhost:8080/api/index.php?action=game_state
```

Recommended migration order:

1. Port information architecture (page list and URL structure)
2. Port layout blocks and assets
3. Align styles to current site visual language
4. Validate mobile and desktop behavior

## Quick Start (Development)

```bash
# 1. Install PHP and MySQL
sudo apt-get install -y php php-mysql mysql-server

# 2. Start MySQL and create database
sudo service mysql start
sudo mysql -e "CREATE DATABASE too_many_coins;"

# 3. Load schema and data
sudo mysql -e "USE too_many_coins; SOURCE schema.sql;"
sudo mysql -e "USE too_many_coins; SOURCE seed_data.sql;"
sudo mysql -e "USE too_many_coins; SOURCE migration_boosts_drops.sql;"
sudo mysql too_many_coins -e "ALTER TABLE season_participation ADD COLUMN sigil_drops_total INT NOT NULL DEFAULT 0, ADD COLUMN eligible_ticks_since_last_drop BIGINT NOT NULL DEFAULT 0;"

# 4. Start the development server
php -S 0.0.0.0:8080 router.php

# 5. Open http://localhost:8080 in your browser
```

## Production Deployment

```bash
# Automated setup on a fresh Ubuntu server:
sudo TMC_DOMAIN=yourdomain.com ./setup.sh

# The script will:
# - Install Apache, PHP, MySQL
# - Create the database and user
# - Load all schema and data
# - Configure Apache virtual host
# - Set up cron for tick processing
# - Output the database credentials

# For SSL:
sudo apt-get install certbot python3-certbot-apache
sudo certbot --apache -d yourdomain.com
```

## Dokploy + Hostinger MySQL Deployment

For Ubuntu 24.04 VPS deployments with Dokploy and a Hostinger-hosted MySQL database, follow:

- `DEPLOY_DOKPLOY_HOSTINGER.md`

## API Endpoints

All endpoints are accessed via `POST /api/index.php?action=<action>` with JSON body.

### Public Endpoints

| Action | Description |
|--------|-------------|
| `game_state` | Full game state (seasons, player data, boosts, drops) |
| `register` | Create account (handle, email, password) |
| `login` | Login (email, password) |
| `season_detail` | Season details (season_id) |
| `season_leaderboard` | Season rankings (season_id) |
| `global_leaderboard` | Global rankings |
| `cosmetics_catalog` | Cosmetic items catalog |
| `chat_history` | Chat messages (channel, season_id) |
| `tick` | Runs one server tick pulse (requires `X-Tick-Secret`) |

## Tick Processing in Production

The server supports two production-safe tick models:

1. Dedicated internal worker (recommended for Dokploy)
2. Scheduler endpoint (fallback)

### Dedicated Internal Worker (Recommended)

Run a separate worker process/service from the same image:

```bash
/app/docker/worker-entrypoint.sh
```

This executes `php /app/worker/tick_worker.php`, which calls `TickEngine::processTicks()` directly on an interval and does not require public HTTP calls.

Recommended env for worker-based processing:

- `TMC_TICK_ON_REQUEST=false`
- `TMC_TICK_REAL_SECONDS=5` (or your target cadence)
- `TMC_WORKER_INTERVAL_SECONDS=5`
- `TMC_WORKER_START_DELAY_SECONDS=2` (optional)
- `TMC_WORKER_ERROR_BACKOFF_SECONDS=2` (optional)

Worker safety:

- Uses MySQL advisory lock `GET_LOCK('tmc_tick_worker', 0)` to avoid concurrent tick execution if more than one worker replica is accidentally started.

### Scheduler Endpoint (Fallback)

If you cannot run a worker service, use the dedicated scheduler endpoint:

- `POST /api/index.php?action=tick`
- Header: `X-Tick-Secret: <TMC_TICK_SECRET>`

Recommended environment variables:

- `TMC_TICK_SECRET=<strong-random-secret>`
- `TMC_TICK_ON_REQUEST=false`
- `TMC_TICK_REAL_SECONDS=60`
- `TMC_TIME_SCALE=1`

Then schedule a request every 1 minute (Dokploy schedule or external cron):

```bash
curl -sS -X POST "https://your-domain/api/index.php?action=tick" \
	-H "X-Tick-Secret: $TMC_TICK_SECRET"
```

### Authenticated Endpoints (require `X-Session-Token` header)

| Action | Description |
|--------|-------------|
| `season_join` | Join a season (season_id) |
| `idle_ack` | Acknowledge idle status |
| `purchase_stars` | Buy stars by quantity (stars_requested) |
| `purchase_vault` | Buy sigil from vault (tier) |
| `purchase_boost` | Activate a boost (boost_id) |
| `lock_in` | Lock-in and exit season |
| `create_trade` | Create a trade offer |
| `accept_trade` | Accept a trade (trade_id) |
| `cancel_trade` | Cancel your trade (trade_id) |
| `chat_send` | Send message (channel, content, season_id) |
| `buy_cosmetic` | Purchase cosmetic (cosmetic_id) |
| `boost_catalog` | Get available boosts |
| `active_boosts` | Get active boosts |
| `sigil_drops` | Get recent sigil drops |

## Sigil Vault Tiers

| Tier | Supply | Cost | Description |
|------|--------|------|-------------|
| I | 2,500 | 5 stars | Common |
| II | 1,000 | 20 stars | Uncommon |
| III | 500 | 50 stars | Rare |
| IV | 250 | 100 stars | Epic |
| V | 100 | 250 stars | Legendary |

## Season Lifecycle

1. **Scheduled**: Season created, waiting for start time
2. **Active**: Players can join, earn UBI, buy stars, trade, collect sigils, activate boosts
3. **Blackout**: Final period, no new joins, star price frozen, last chance to Lock-In
4. **Expired**: Season ended, final standings calculated, Global Stars awarded

## License

This project is based on the Too Many Coins game design documentation.
