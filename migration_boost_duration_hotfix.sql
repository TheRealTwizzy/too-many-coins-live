-- One-time hotfix: align existing boost catalog with canonical production definitions.
-- Safe to run multiple times (idempotent updates).
-- Context: 1 tick = 1 minute.

START TRANSACTION;

-- Tier I (SELF): Trickle, +25%, 15 minutes
UPDATE boost_catalog
SET name = 'Trickle',
    description = 'Increases your UBI by 25% for 15 minutes.',
    scope = 'SELF',
    duration_ticks = 15,
    modifier_fp = 250000,
    max_stack = 3,
    icon = 'trickle',
    sigil_cost = 1
WHERE tier_required = 1;

-- Tier II (SELF): Surge, +50%, 30 minutes
UPDATE boost_catalog
SET name = 'Surge',
    description = 'Increases your UBI by 50% for 30 minutes.',
    scope = 'SELF',
    duration_ticks = 30,
    modifier_fp = 500000,
    max_stack = 2,
    icon = 'surge',
    sigil_cost = 1
WHERE tier_required = 2;

-- Tier III (SELF): Flow, +75%, 1 hour
UPDATE boost_catalog
SET name = 'Flow',
    description = 'Increases your UBI by 75% for 1 hour.',
    scope = 'SELF',
    duration_ticks = 60,
    modifier_fp = 750000,
    max_stack = 1,
    icon = 'flow',
    sigil_cost = 1
WHERE tier_required = 3;

-- Tier IV (GLOBAL): Tide, +15%, 24 hours
UPDATE boost_catalog
SET name = 'Tide',
    description = 'Increases UBI by 15% for all players for 24 hours.',
    scope = 'GLOBAL',
    duration_ticks = 1440,
    modifier_fp = 150000,
    max_stack = 1,
    icon = 'tide',
    sigil_cost = 1
WHERE tier_required = 4;

-- Tier V (GLOBAL): Age, +30%, 48 hours
UPDATE boost_catalog
SET name = 'Age',
    description = 'Increases UBI by 30% for all players for 48 hours.',
    scope = 'GLOBAL',
    duration_ticks = 2880,
    modifier_fp = 300000,
    max_stack = 1,
    icon = 'age',
    sigil_cost = 1
WHERE tier_required = 5;

COMMIT;
