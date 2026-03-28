-- One-time hotfix: align existing boost catalog with canonical production definitions.
-- Safe to run multiple times (idempotent updates).
-- Context: 1 tick = 1 minute.

START TRANSACTION;

-- Tier I (SELF): Trickle, +10%, 12 hours
UPDATE boost_catalog
SET name = 'Trickle',
    description = '',
    scope = 'SELF',
    duration_ticks = 720,
    modifier_fp = 100000,
    max_stack = 5,
    icon = 'trickle',
    sigil_cost = 1
WHERE tier_required = 1;

-- Tier II (SELF): Surge, +15%, 36 hours
UPDATE boost_catalog
SET name = 'Surge',
    description = '',
    scope = 'SELF',
    duration_ticks = 2160,
    modifier_fp = 150000,
    max_stack = 5,
    icon = 'surge',
    sigil_cost = 1
WHERE tier_required = 2;

-- Tier III (SELF): Flow, +25%, 72 hours
UPDATE boost_catalog
SET name = 'Flow',
    description = '',
    scope = 'SELF',
    duration_ticks = 4320,
    modifier_fp = 250000,
    max_stack = 2,
    icon = 'flow',
    sigil_cost = 1
WHERE tier_required = 3;

-- Tier IV (SELF): Tide, +50%, 144 hours
UPDATE boost_catalog
SET name = 'Tide',
    description = '',
    scope = 'SELF',
    duration_ticks = 8640,
    modifier_fp = 500000,
    max_stack = 1,
    icon = 'tide',
    sigil_cost = 1
WHERE tier_required = 4;

-- Tier V (SELF): Age, +100%, 288 hours
UPDATE boost_catalog
SET name = 'Age',
    description = '',
    scope = 'SELF',
    duration_ticks = 17280,
    modifier_fp = 1000000,
    max_stack = 1,
    icon = 'age',
    sigil_cost = 1
WHERE tier_required = 5;

COMMIT;
