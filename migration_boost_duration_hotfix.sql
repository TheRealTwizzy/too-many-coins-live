-- One-time hotfix: align existing boost catalog with canonical production definitions.
-- Safe to run multiple times (idempotent updates).
-- Context: 1 tick = 1 minute.

START TRANSACTION;

-- Tier I (SELF): Trickle, +10%, 1 hour
UPDATE boost_catalog
SET name = 'Trickle',
    description = 'Increases your UBI by 10% for 1 hour.',
    scope = 'SELF',
    duration_ticks = 60,
    modifier_fp = 100000,
    max_stack = 5,
    icon = 'trickle',
    sigil_cost = 1
WHERE tier_required = 1;

-- Tier II (SELF): Surge, +15%, 3 hours
UPDATE boost_catalog
SET name = 'Surge',
    description = 'Increases your UBI by 15% for 3 hours.',
    scope = 'SELF',
    duration_ticks = 180,
    modifier_fp = 150000,
    max_stack = 5,
    icon = 'surge',
    sigil_cost = 1
WHERE tier_required = 2;

-- Tier III (SELF): Flow, +25%, 6 hours
UPDATE boost_catalog
SET name = 'Flow',
    description = 'Increases your UBI by 25% for 6 hours.',
    scope = 'SELF',
    duration_ticks = 360,
    modifier_fp = 250000,
    max_stack = 2,
    icon = 'flow',
    sigil_cost = 1
WHERE tier_required = 3;

-- Tier IV (SELF): Tide, +50%, 12 hours
UPDATE boost_catalog
SET name = 'Tide',
    description = 'Increases your UBI by 50% for 12 hours.',
    scope = 'SELF',
    duration_ticks = 720,
    modifier_fp = 500000,
    max_stack = 1,
    icon = 'tide',
    sigil_cost = 1
WHERE tier_required = 4;

-- Tier V (SELF): Age, +100%, 24 hours
UPDATE boost_catalog
SET name = 'Age',
    description = 'Increases your UBI by 100% for 24 hours.',
    scope = 'SELF',
    duration_ticks = 1440,
    modifier_fp = 1000000,
    max_stack = 1,
    icon = 'age',
    sigil_cost = 1
WHERE tier_required = 5;

COMMIT;
