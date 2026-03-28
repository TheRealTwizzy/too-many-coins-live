// Repository-grounded wiki content for Too Many Coins.
// Source of truth: includes/*.php, api/index.php, schema.sql, migration_boosts_drops.sql.
window.WIKI_CATEGORIES = [
  {
    id: "getting-started",
    title: "Getting Started",
    chapters: [
      {
        id: "what-is-tmc",
        number: 1,
        title: "What Is Too Many Coins?",
        icon: "BookOpen",
        description: "A season-based economic competition built around timing and tradeoffs.",
        sections: [
          {
            id: "game-overview",
            title: "Game Overview",
            content: `Too Many Coins is a season-based economic game where you convert **Coins** into **Seasonal Stars** and compete on a live leaderboard.

A season has three states:
- **Active**
- **Blackout**
- **Expired**

Your long-term score is **Global Stars**, earned by Lock-In or by finishing a season at natural expiration.`
          },
          {
            id: "season-structure-basics",
            title: "Season Structure Basics",
            content: `Default timing from server config:

| Setting | Default |
|---|---|
| Season duration | 28 days |
| New season cadence | Every 7 days |
| Blackout length | 72 hours |
| Tick cadence | 60 real seconds per tick |

Because cadence is 7 days and duration is 28 days, multiple seasons overlap at the same time.`
          },
          {
            id: "first-goals",
            title: "Your First Goals",
            content: `Start simple:
1. Join an **Active** season.
2. Stay **Active** (not Idle) so your income is stronger.
3. Convert Coins into Seasonal Stars.
4. Decide whether to Lock-In before Blackout.

There is no hidden bonus path; progress is driven by your economy choices.`
          }
        ]
      },
      {
        id: "first-season",
        number: 2,
        title: "Your First Season",
        icon: "Compass",
        description: "Join flow, action limits, and what to expect in your first run.",
        sections: [
          {
            id: "joining-rules",
            title: "Joining Rules",
            content: `You can join a season if it has started and has not expired.

Important constraints from action logic:
- Staff accounts cannot participate in seasons.
- You cannot participate in multiple seasons at once.
- Joining at the very end is blocked.

Re-entry is allowed if you previously left, but season-bound resources are reset for that season entry.`
          },
          {
            id: "idle-and-actions",
            title: "Idle State and Action Gating",
            content: `If you go Idle long enough, the game gates economy actions until you acknowledge idle.

By default, idle timeout is **15 real minutes**. While idle modal is active, actions like star purchase, Lock-In, boosts, and trades are blocked until **idle acknowledgement**.`
          },
          {
            id: "what-carries-over",
            title: "What Carries Over",
            content: `Season-bound resources are temporary:
- Coins
- Seasonal Stars
- Sigils
- Active boosts

Persistent account-level progression includes:
- Global Stars
- Purchased cosmetics
- Badges earned from placements`
          }
        ]
      }
    ]
  },
  {
    id: "gameplay",
    title: "Gameplay",
    chapters: [
      {
        id: "seasons-guide",
        number: 3,
        title: "Season States and Timing",
        icon: "Clock",
        description: "How Active, Blackout, and Expired affect available actions.",
        sections: [
          {
            id: "active-state",
            title: "Active State",
            content: `During **Active**, core economy actions are available:
- Buy stars
- Buy vault sigils
- Activate boosts
- Trade
- Lock-In`
          },
          {
            id: "blackout-state",
            title: "Blackout State",
            content: `During **Blackout**, several actions are intentionally disabled:
- Lock-In
- Vault purchases
- Boost activation
- Trade initiation

You can still hold your position and continue through the final stretch to expiration.`
          },
          {
            id: "expired-state",
            title: "Expired and Finalization",
            content: `When a season expires, the tick engine finalizes it:
- Marks end-finishers
- Calculates final ranking
- Converts end-finishers' Seasonal Stars to Global Stars 1:1
- Applies participation and placement bonuses
- Awards top-3 seasonal badges
- Clears season-bound resources`
          }
        ]
      },
      {
        id: "resources-guide",
        number: 4,
        title: "Coins, Stars, and UBI",
        icon: "Coins",
        description: "How the economy mints, prices, and suppresses resources.",
        sections: [
          {
            id: "ubi-baseline",
            title: "UBI Baseline",
            content: `Season defaults are configured at season creation:

| Parameter | Default |
|---|---|
| Base active UBI per tick | 30 |
| Idle factor | 0.25 |
| Minimum UBI floor | 1 |

UBI is further modified by inflation dampening and hoarding suppression.`
          },
          {
            id: "inflation-hoarding",
            title: "Inflation and Hoarding Suppression",
            content: `UBI is reduced as coin supply and hoarding pressure increase:
- Inflation factor uses a piecewise table against total coin supply.
- Hoarding factor compares your spend rate against target spend rate.

Default hoarding window is 24 hours and minimum factor clamp is 0.1.`
          },
          {
            id: "star-pricing",
            title: "Star Pricing",
            content: `Star price is season-driven and updates from supply.

Default star price table points:
- 0 supply -> 100
- 25,000 -> 250
- 100,000 -> 700
- 500,000 -> 2,500
- 2,000,000 -> 9,000

Star price cap defaults to **10,000**.`
          }
        ]
      },
      {
        id: "sigils-guide",
        number: 5,
        title: "Sigils, Drops, Vault, and Boosts",
        icon: "Sparkles",
        description: "Exact drop logic and boost behavior from tick/action systems.",
        sections: [
          {
            id: "drop-eligibility",
            title: "Drop Eligibility",
            content: `Sigil drops only evaluate when a player is:
- Online
- Active (not Idle)
- Participating in the season

If you are not eligible, the drop pity counter is reset.`
          },
          {
            id: "drop-rates-and-protections",
            title: "Drop Rates and Protections",
            content: `Default drop controls:

| Rule | Default |
|---|---|
| Base drop rate | 1 in 833 eligible ticks |
| Pity threshold | 120,000 real seconds worth of ticks (2,000 ticks at 60s) |
| Rolling cap | 3 drops per 24h window |

Tier odds:
- Tier I: 70%
- Tier II: 20%
- Tier III: 8%
- Tier IV: 1.5%
- Tier V: 0.5%`
          },
          {
            id: "vault-and-boosts",
            title: "Vault and Boost Activation",
            content: `Vault sigils cost **Seasonal Stars** and are disabled in Blackout.

Default vault supply per season:
- Tier I: 2500
- Tier II: 1000
- Tier III: 500
- Tier IV: 250
- Tier V: 100

Boosts consume sigils and can be:
- **SELF** scope
- **GLOBAL** scope

Global and self boosts stack additively in fixed-point logic and are capped by a total modifier clamp.`
          },
          {
            id: "default-boost-catalog",
            title: "Default Boost Catalog",
            content: `Seeded boost defaults:

| Boost | Tier | Scope | Duration | Modifier |
|---|---|---|---|---|
| Trickle | I | SELF | 60 ticks (1 hour) | +10% |
| Surge | II | SELF | 180 ticks (3 hours) | +15% |
| Flow | III | SELF | 360 ticks (6 hours) | +25% |
| Tide | IV | SELF | 720 ticks (12 hours) | +50% |
| Age | V | SELF | 1440 ticks (24 hours) | +100% |`
          }
        ]
      },
      {
        id: "trading-guide",
        number: 6,
        title: "Trading Rules",
        icon: "ArrowLeftRight",
        description: "Escrow model, valuation, fees, and blackout restrictions.",
        sections: [
          {
            id: "trade-constraints",
            title: "What Trades Are Allowed",
            content: `Trade system constraints:
- Both sides must contribute value.
- Coins-for-coins-only trades are blocked.
- Both players must be in the same season.
- Trade initiation is disabled in Blackout.`
          },
          {
            id: "escrow-and-timeout",
            title: "Escrow and Timeout",
            content: `On initiate, initiator assets and fee are escrowed.

Default timeout is **1 real hour**. Open trades expire automatically at timeout.`
          },
          {
            id: "trade-fees",
            title: "Trade Fee Model",
            content: `Fee is based on declared trade value with tiered rates:

| Threshold | Rate |
|---|---|
| 0+ | 5% |
| 10,000+ | 3% |
| 100,000+ | 2% |

Minimum fee is **10 coins**.

On accepted trades, fees from both parties are burned from season coin supply.`
          }
        ]
      }
    ]
  },
  {
    id: "competition",
    title: "Competition & Prestige",
    chapters: [
      {
        id: "leaderboards",
        number: 7,
        title: "Leaderboards",
        icon: "Trophy",
        description: "How seasonal and global ranking are calculated and displayed.",
        sections: [
          {
            id: "seasonal-ranking",
            title: "Seasonal Ranking",
            content: `Season leaderboard ordering is by:
1. Seasonal Stars (descending)
2. player_id (ascending tiebreak)

Entries include active participants, lock-in snapshots, and end-finishers.`
          },
          {
            id: "global-ranking",
            title: "Global Ranking",
            content: `Global leaderboard is ordered by **global_stars DESC**, then **player_id ASC**, and excludes deleted profiles.`
          }
        ]
      },
      {
        id: "lock-in-guide",
        number: 8,
        title: "Lock-In Mechanics",
        icon: "Lock",
        description: "Exact lock-in behavior and restrictions.",
        sections: [
          {
            id: "lock-in-availability",
            title: "When Lock-In Is Allowed",
            content: `Lock-In requires:
- Participating in a season
- Not idle-gated
- Season status = Active (not Blackout, not Expired)
- At least 1 participation tick since join`
          },
          {
            id: "lock-in-effects",
            title: "What Lock-In Does",
            content: `Lock-In performs the following:
- Saves lock-in snapshot
- Converts Seasonal Stars to Global Stars at 1:1
- Destroys season-bound resources (coins, seasonal stars, sigils, active boosts)
- Exits player from season
- Cancels open trades for that player`
          }
        ]
      },
      {
        id: "prestige-guide",
        number: 9,
        title: "Badges and Cosmetics",
        icon: "Award",
        description: "What prestige systems are currently active in code.",
        sections: [
          {
            id: "seasonal-badges",
            title: "Seasonal Badge Awards",
            content: `At season expiration, if the top seasonal score is greater than zero, top end-finishers receive:
- Rank 1 -> seasonal_first
- Rank 2 -> seasonal_second
- Rank 3 -> seasonal_third`
          },
          {
            id: "cosmetics-system",
            title: "Cosmetics",
            content: `Cosmetics are purchased with Global Stars from the cosmetic catalog and can be equipped per category.

Default price tiers used in seed data:
- 10, 25, 60, 150, 400 Global Stars`
          },
          {
            id: "scope-note",
            title: "Scope Note",
            content: `This wiki reflects active game logic. It does not assume unreleased lifecycle features unless they are wired into runtime behavior.`
          }
        ]
      }
    ]
  },
  {
    id: "social",
    title: "Social & Community",
    chapters: [
      {
        id: "social-features",
        number: 10,
        title: "Chat and Profiles",
        icon: "Users",
        description: "Channels, limits, and profile data exposed by API.",
        sections: [
          {
            id: "chat-channels",
            title: "Chat Channels in Current Client/API",
            content: `Client gameplay surfaces and API retrieval currently support:
- GLOBAL channel
- SEASON channel

Server-side send endpoint can accept DM channel payloads, but player-facing retrieval in current API helper is focused on GLOBAL and SEASON.`
          },
          {
            id: "chat-limits",
            title: "Chat Limits",
            content: `Configured chat limits:
- Max message length: 500
- Max rows returned per fetch: 200`
          },
          {
            id: "profiles",
            title: "Profiles and History",
            content: `Profile endpoint includes:
- Handle and role
- Global Stars
- Badges
- Season history snapshots

Deleted profiles return as removed placeholders.`
          }
        ]
      },
      {
        id: "rules-fairness",
        number: 11,
        title: "Fairness Boundaries",
        icon: "Shield",
        description: "What restrictions and safeguards are explicit in runtime logic.",
        sections: [
          {
            id: "staff-participation",
            title: "Staff Participation Restriction",
            content: `Staff roles are blocked from season participation by action logic.`
          },
          {
            id: "action-gating",
            title: "Action Gating and Safety",
            content: `Core actions are protected by:
- Auth checks
- Idle gating
- Season-state checks
- Input validation
- Rate limiting at API level`
          },
          {
            id: "deterministic-surfaces",
            title: "Deterministic Economy Surfaces",
            content: `Drop RNG, UBI math, star pricing, and fee logic are defined server-side so economy outcomes do not depend on client-side trust.`
          }
        ]
      }
    ]
  },
  {
    id: "strategy",
    title: "Strategy & Tips",
    chapters: [
      {
        id: "strategy-guide",
        number: 12,
        title: "Practical Strategy",
        icon: "Lightbulb",
        description: "Code-grounded guidance for better decision timing.",
        sections: [
          {
            id: "blackout-planning",
            title: "Plan Around Blackout",
            content: `Because Lock-In, vault purchases, boost activation, and trade initiation are blocked in Blackout, front-load your critical actions during Active state.`
          },
          {
            id: "stars-vs-power",
            title: "Stars vs Temporary Power",
            content: `Spending Seasonal Stars on vault sigils can improve short-term UBI through boosts, but it can reduce immediate leaderboard rank. Decide based on your timing and season status.`
          },
          {
            id: "trade-discipline",
            title: "Trade Discipline",
            content: `Always account for fee burn and timeout risk.

A trade that looks neutral before fees can become negative after both-side fee constraints and missed expiration windows.`
          },
          {
            id: "stay-active",
            title: "Stay Active",
            content: `Active status matters twice:
- Better UBI branch than idle factor branch
- Eligibility for sigil drops

If you are idle-gated, acknowledge promptly to restore full action access.`
          }
        ]
      },
      {
        id: "glossary",
        number: 13,
        title: "Glossary",
        icon: "BookText",
        description: "Terms as implemented in current server logic.",
        sections: [
          {
            id: "terms-core",
            title: "Core Terms",
            content: `**Seasonal Stars**: Per-season ranking resource.

**Global Stars**: Cross-season persistent score used for global leaderboard and cosmetic purchases.

**Blackout**: Final season window where major economy actions are restricted.

**Lock-In**: Early exit that converts Seasonal Stars to Global Stars and clears season-bound resources.`
          },
          {
            id: "terms-economy",
            title: "Economy Terms",
            content: `**UBI**: Per-tick coin accrual with activity, inflation, and hoarding modifiers.

**Vault**: Tiered sigil inventory with dynamic star costs based on remaining supply.

**Declared Trade Value**: Combined valuation used to compute trade fees.

**Participation Bonus**: Expiration-time bonus based on active ticks, capped at 56 by default.`
          },
          {
            id: "terms-drops",
            title: "Drop Terms",
            content: `**RNG Drop**: Sigil drop from deterministic Bernoulli trial.

**Pity Drop**: Forced Tier I drop when pity threshold is reached.

**Drop Throttle**: Rolling cap that limits drops within the configured window.`
          }
        ]
      }
    ]
  }
];
