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
| Coin Trickle | I | +10% UBI | 1 hour | Self |
| Coin Surge | II | +25% UBI | 2 hours | Self |
| Golden Flow | III | +50% UBI | 3 hours | Self |
| Rising Tide | IV | +15% UBI | 1 hour | All Players |
| Golden Age | V | +30% UBI | 2 hours | All Players |

## Sigil Drop System

- **Base Rate**: 1 in 50,000 per eligible tick (Bernoulli trial)
- **Eligibility**: Must be Online + Participating + Active (not Idle)
- **Pity Timer**: Guaranteed Tier I drop after 120,000 eligible ticks with no drop
- **Throttle**: Maximum 3 drops per rolling 86,400-tick window
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
│   └── js/app.js               # Game client JavaScript
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
├── router.php                  # PHP dev server router
├── setup.sh                    # Production deployment script
└── README.md                   # This file
```

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

### Authenticated Endpoints (require `X-Session-Token` header)

| Action | Description |
|--------|-------------|
| `season_join` | Join a season (season_id) |
| `idle_ack` | Acknowledge idle status |
| `purchase_stars` | Buy stars with coins (coins_to_spend) |
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

| Tier | Supply | Base Cost | Description |
|------|--------|-----------|-------------|
| I | 2,000 | 2 stars | Common |
| II | 800 | 6 stars | Uncommon |
| III | 250 | 18 stars | Rare |
| IV | 60 | 60 stars | Epic |
| V | 15 | 220 stars | Legendary |

## Season Lifecycle

1. **Scheduled**: Season created, waiting for start time
2. **Active**: Players can join, earn UBI, buy stars, trade, collect sigils, activate boosts
3. **Blackout**: Final period, no new joins, star price frozen, last chance to Lock-In
4. **Expired**: Season ended, final standings calculated, Global Stars awarded

## License

This project is based on the Too Many Coins game design documentation.
