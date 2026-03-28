-- Hotfix: add static boost-catalog leverage fields for Sigil Vault pricing/stock surfaces.
-- Safe to run multiple times.

START TRANSACTION;

SET @has_price_discount := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'boost_catalog'
      AND COLUMN_NAME = 'vault_price_discount_fp'
);
SET @sql_add_price_discount := IF(
    @has_price_discount = 0,
    'ALTER TABLE boost_catalog ADD COLUMN vault_price_discount_fp INT NOT NULL DEFAULT 0 AFTER sigil_cost',
    'SELECT 1'
);
PREPARE stmt_add_price_discount FROM @sql_add_price_discount;
EXECUTE stmt_add_price_discount;
DEALLOCATE PREPARE stmt_add_price_discount;

SET @has_stock_leverage := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'boost_catalog'
      AND COLUMN_NAME = 'vault_stock_leverage_fp'
);
SET @sql_add_stock_leverage := IF(
    @has_stock_leverage = 0,
    'ALTER TABLE boost_catalog ADD COLUMN vault_stock_leverage_fp INT NOT NULL DEFAULT 1000000 AFTER vault_price_discount_fp',
    'SELECT 1'
);
PREPARE stmt_add_stock_leverage FROM @sql_add_stock_leverage;
EXECUTE stmt_add_stock_leverage;
DEALLOCATE PREPARE stmt_add_stock_leverage;

UPDATE boost_catalog SET vault_price_discount_fp = 0,      vault_stock_leverage_fp = 1000000 WHERE tier_required = 1;
UPDATE boost_catalog SET vault_price_discount_fp = 50000,  vault_stock_leverage_fp = 1100000 WHERE tier_required = 2;
UPDATE boost_catalog SET vault_price_discount_fp = 100000, vault_stock_leverage_fp = 1250000 WHERE tier_required = 3;
UPDATE boost_catalog SET vault_price_discount_fp = 150000, vault_stock_leverage_fp = 1400000 WHERE tier_required = 4;
UPDATE boost_catalog SET vault_price_discount_fp = 200000, vault_stock_leverage_fp = 1600000 WHERE tier_required = 5;

COMMIT;
