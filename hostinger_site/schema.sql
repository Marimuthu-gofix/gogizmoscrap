-- Buyback price database tables (MySQL / MariaDB, Hostinger).
--
-- You normally do NOT need this file: the site and the API create these
-- tables by themselves on first use. It is here for a manual setup, a new
-- database, or as a reference.
--
-- How to run it: hPanel > Databases > phpMyAdmin > choose your database on
-- the left > Import tab > choose this file > Go.  (Or paste it into the SQL
-- tab and press Go.)
--
-- It is safe to run on a database that already has the tables: every
-- statement uses IF NOT EXISTS, so nothing is changed or deleted.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Sign-in accounts for the dashboard (admin / user).
--    Rows are added from the site: the first visit creates the admin,
--    and the admin adds everyone else under Accounts.
--    password_hash holds a scrambled password, never the real one.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS buyback_users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(60)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          VARCHAR(10)  NOT NULL DEFAULT 'user',   -- 'admin' or 'user'
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Scraped buyback prices. One row per check of one phone variant on
--    one platform. Written by the scraper through /api/prices; read by
--    the dashboards.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS buyback_prices (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  date        VARCHAR(40)  NOT NULL,          -- when it was checked, ISO time in UTC
  platform    VARCHAR(20)  NOT NULL,          -- 'Cashify' or 'DOFY'
  brand       VARCHAR(100) NOT NULL,
  model       VARCHAR(191) NOT NULL,
  variant     VARCHAR(100) NOT NULL DEFAULT '', -- e.g. '8 GB/256 GB'
  price       VARCHAR(255) NULL,              -- the price as text, e.g. '₹41,820'
  price_value DOUBLE NULL,                    -- the number, for sorting and Excel
  currency    VARCHAR(10) NULL,
  url         TEXT NOT NULL,                  -- the source page
  status      VARCHAR(20) NOT NULL,           -- 'ok' or 'error'
  error       TEXT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_observation (date, platform, brand, model, variant),
  KEY idx_lookup (platform, brand, model, variant, date),
  KEY idx_status_date (status, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
