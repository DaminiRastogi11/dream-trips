-- =====================================================================
-- Dream Trips — travel_itinerary_db schema + seed data
-- =====================================================================
-- This file is safe to import into a fresh MySQL/MariaDB instance via
-- phpMyAdmin (Import) or `mysql -u root travel_itinerary_db < file.sql`.
--
-- It also embeds the FIX 3A migration (drop the orphan
-- itinerary_activity.activity_entry_id column) at the very top so that
-- if you re-run this file against an older database, the old NOT NULL
-- column is removed before the seed inserts run.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS travel_itinerary_db
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE travel_itinerary_db;

-- ---------------------------------------------------------------------
-- FIX 3A: drop orphan activity_entry_id column (idempotent)
-- ---------------------------------------------------------------------
-- If you only need the migration on an existing DB, run JUST this block
-- in phpMyAdmin:
--
--   ALTER TABLE itinerary_activity DROP COLUMN activity_entry_id;
--
-- The wrapper below makes it safe to re-run on a fresh DB where the
-- table doesn't yet exist.
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'itinerary_activity'
    AND COLUMN_NAME  = 'activity_entry_id'
);
SET @sql := IF(@col_exists > 0,
  'ALTER TABLE itinerary_activity DROP COLUMN activity_entry_id',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- v2 MIGRATIONS — idempotent ALTER ADD COLUMN guards
-- (start_date, budget, deleted_at, share_token on itinerary)
-- ---------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itinerary' AND COLUMN_NAME='start_date');
SET @sql := IF(@c=0, 'ALTER TABLE itinerary ADD COLUMN start_date DATE NULL AFTER destination_id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itinerary' AND COLUMN_NAME='budget');
SET @sql := IF(@c=0, 'ALTER TABLE itinerary ADD COLUMN budget DECIMAL(10,2) DEFAULT 0 AFTER start_date', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itinerary' AND COLUMN_NAME='deleted_at');
SET @sql := IF(@c=0, 'ALTER TABLE itinerary ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='itinerary' AND COLUMN_NAME='share_token');
SET @sql := IF(@c=0, 'ALTER TABLE itinerary ADD COLUMN share_token CHAR(32) NULL DEFAULT NULL UNIQUE', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- Drop in dependency order (so re-import is clean)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS itinerary_activity;
DROP TABLE IF EXISTS itinerary_day;
DROP TABLE IF EXISTS itinerary;
DROP TABLE IF EXISTS accommodation;
DROP TABLE IF EXISTS event;
DROP TABLE IF EXISTS attraction;
DROP TABLE IF EXISTS traveler_preferences;
DROP TABLE IF EXISTS destination;
DROP TABLE IF EXISTS traveler;

-- =====================================================================
-- TABLES
-- =====================================================================

CREATE TABLE traveler (
    traveler_id   INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120) NOT NULL,
    email         VARCHAR(190) NOT NULL UNIQUE,
    password      VARCHAR(255) NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE destination (
    destination_id INT AUTO_INCREMENT PRIMARY KEY,
    city           VARCHAR(120) NOT NULL,
    country        VARCHAR(120) NOT NULL,
    climate        ENUM('Mild','Hot','Seasonal','Cold') DEFAULT 'Mild',
    visa_required  ENUM('Yes','No') DEFAULT 'No',
    image_url      VARCHAR(500) DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE traveler_preferences (
    traveler_id        INT PRIMARY KEY,
    max_budget         INT DEFAULT 0,
    preferred_climate  VARCHAR(40) DEFAULT '',
    visa_required      VARCHAR(10) DEFAULT 'Either',
    trip_type          VARCHAR(40) DEFAULT 'Leisure',
    CONSTRAINT fk_pref_traveler
      FOREIGN KEY (traveler_id) REFERENCES traveler(traveler_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE attraction (
    attraction_id   INT AUTO_INCREMENT PRIMARY KEY,
    destination_id  INT NOT NULL,
    name            VARCHAR(150) NOT NULL,
    category        VARCHAR(60) DEFAULT NULL,
    rating          DECIMAL(2,1) DEFAULT 0.0,
    entry_fee       DECIMAL(8,2) DEFAULT 0.00,
    avg_time_hours  DECIMAL(4,1) DEFAULT 1.0,
    CONSTRAINT fk_att_dest FOREIGN KEY (destination_id)
        REFERENCES destination(destination_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE event (
    event_id        INT AUTO_INCREMENT PRIMARY KEY,
    destination_id  INT NOT NULL,
    name            VARCHAR(150) NOT NULL,
    description     VARCHAR(500) DEFAULT '',
    start_date      DATE DEFAULT NULL,
    end_date        DATE DEFAULT NULL,
    price           DECIMAL(8,2) DEFAULT 0.00,
    CONSTRAINT fk_evt_dest FOREIGN KEY (destination_id)
        REFERENCES destination(destination_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE accommodation (
    accommodation_id INT AUTO_INCREMENT PRIMARY KEY,
    destination_id   INT NOT NULL,
    name             VARCHAR(150) NOT NULL,
    cost_per_night   DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT fk_acc_dest FOREIGN KEY (destination_id)
        REFERENCES destination(destination_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE itinerary (
    itinerary_id    INT AUTO_INCREMENT PRIMARY KEY,
    traveler_id     INT NOT NULL,
    destination_id  INT NOT NULL,
    start_date      DATE NULL,
    budget          DECIMAL(10,2) DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    deleted_at      DATETIME NULL DEFAULT NULL,
    share_token     CHAR(32) NULL DEFAULT NULL UNIQUE,
    CONSTRAINT fk_itin_traveler FOREIGN KEY (traveler_id)
        REFERENCES traveler(traveler_id) ON DELETE CASCADE,
    CONSTRAINT fk_itin_dest FOREIGN KEY (destination_id)
        REFERENCES destination(destination_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE itinerary_day (
    day_id           INT AUTO_INCREMENT PRIMARY KEY,
    itinerary_id     INT NOT NULL,
    day_number       INT NOT NULL,
    accommodation_id INT DEFAULT NULL,
    notes            VARCHAR(500) DEFAULT '',
    CONSTRAINT fk_day_itin FOREIGN KEY (itinerary_id)
        REFERENCES itinerary(itinerary_id) ON DELETE CASCADE,
    CONSTRAINT fk_day_acc  FOREIGN KEY (accommodation_id)
        REFERENCES accommodation(accommodation_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- NOTE: FIX 3A — no activity_entry_id column. itinerary_activity_id is
-- the only PK / surrogate id we need on this table.
CREATE TABLE itinerary_activity (
    itinerary_activity_id INT AUTO_INCREMENT PRIMARY KEY,
    day_id                INT NOT NULL,
    activity_type         ENUM('attraction','event') NOT NULL,
    activity_id           INT NOT NULL,
    CONSTRAINT fk_act_day FOREIGN KEY (day_id)
        REFERENCES itinerary_day(day_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
-- SEED DATA
-- =====================================================================

-- FIX 3D: image_url is now stored in the DB rather than hardcoded in PHP.
INSERT INTO destination (destination_id, city, country, climate, visa_required, image_url) VALUES
  (1, 'Paris', 'France',  'Mild',     'No',
   'https://images.unsplash.com/photo-1502602898657-3e91760cbb34?auto=compress&cs=tinysrgb&w=1200'),
  (2, 'Tokyo', 'Japan',   'Seasonal', 'Yes',
   'https://media.istockphoto.com/id/1390815938/photo/tokyo-city-in-japan.jpg?s=612x612&w=0&k=20&c=VHiC3TlbXkb-Yf6WUYjh825Y0nGMCTkNUa9j8X8rVfY='),
  (3, 'Cairo', 'Egypt',   'Hot',      'Yes',
   'https://media.gettyimages.com/id/1404746517/photo/bedouins-and-pyramids.jpg?s=612x612&w=0&k=20&c=OAwETyeofhQ_SFoRfps2b-7zPDKGSHff94ch-ZzIWD0='),
  (4, 'Bali',      'Indonesia', 'Hot',      'No',
   'https://images.unsplash.com/photo-1537996194471-e657df975ab4?auto=compress&cs=tinysrgb&w=1200'),
  (5, 'Barcelona', 'Spain',     'Mild',     'No',
   'https://images.unsplash.com/photo-1583422409516-2895a77efded?auto=compress&cs=tinysrgb&w=1200'),
  (6, 'Reykjavik', 'Iceland',   'Cold',     'No',
   'https://images.unsplash.com/photo-1504284769763-88de03e6e6e9?auto=compress&cs=tinysrgb&w=1200'),
  (7, 'Bangkok',   'Thailand',  'Hot',      'No',
   'https://images.unsplash.com/photo-1508009603885-50cf7c579365?auto=compress&cs=tinysrgb&w=1200'),
  (8, 'New York',  'USA',       'Seasonal', 'Yes',
   'https://images.unsplash.com/photo-1496442226666-8d4d0e62e6e9?auto=compress&cs=tinysrgb&w=1200');

-- FIX 3B: realistic categories + ratings.
INSERT INTO attraction (attraction_id, destination_id, name, category, rating, entry_fee, avg_time_hours) VALUES
  (1, 1, 'Eiffel Tower',        'Landmark',   4.8, 28.00, 2.5),
  (2, 1, 'Louvre Museum',       'Museum',     4.7, 22.00, 3.0),
  (3, 1, 'Seine River Cruise',  'Experience', 4.5, 18.00, 1.5),
  (4, 2, 'Tokyo Skytree',       'Landmark',   4.3, 25.00, 2.0),
  (5, 2, 'Shinjuku Gyoen Park', 'Nature',     4.6,  5.00, 2.0),
  (6, 2, 'TeamLab Borderless',  'Experience', 4.9, 32.00, 3.0),
  (7, 3, 'Pyramids of Giza',    'Landmark',   4.9, 20.00, 3.5),
  (8, 3, 'Egyptian Museum',     'Museum',     4.4, 15.00, 2.5),
  (9, 3, 'Nile River Cruise',   'Experience', 4.6, 45.00, 4.0),
  -- Bali
  (10, 4, 'Uluwatu Temple',          'Landmark',   4.7, 12.00, 2.0),
  (11, 4, 'Tegallalang Rice Terraces','Nature',    4.6,  5.00, 2.5),
  (12, 4, 'Sacred Monkey Forest',    'Nature',     4.4,  7.00, 1.5),
  -- Barcelona
  (13, 5, 'Sagrada Familia',         'Landmark',   4.9, 26.00, 2.0),
  (14, 5, 'Park Güell',              'Nature',     4.6, 10.00, 2.0),
  (15, 5, 'Picasso Museum',          'Museum',     4.5, 14.00, 2.0),
  -- Reykjavik
  (16, 6, 'Blue Lagoon',             'Experience', 4.7, 95.00, 3.0),
  (17, 6, 'Hallgrimskirkja',         'Landmark',   4.6,  5.00, 1.0),
  (18, 6, 'Northern Lights Tour',    'Experience', 4.8,120.00, 4.0),
  -- Bangkok
  (19, 7, 'Grand Palace',            'Landmark',   4.7, 16.00, 2.5),
  (20, 7, 'Wat Pho Temple',          'Landmark',   4.6,  6.00, 1.5),
  (21, 7, 'Chatuchak Weekend Market','Experience', 4.5,  0.00, 3.0),
  -- New York
  (22, 8, 'Statue of Liberty',       'Landmark',   4.7, 24.00, 3.0),
  (23, 8, 'Metropolitan Museum',     'Museum',     4.8, 30.00, 3.0),
  (24, 8, 'Top of the Rock',         'Experience', 4.7, 40.00, 1.5);

-- FIX 3C: plausible 2025/2026 event dates (instead of NULL).
INSERT INTO event (event_id, destination_id, name, description, start_date, end_date, price) VALUES
  (1, 1, 'Bastille Day Fireworks', 'Annual fireworks at the Eiffel Tower.',
       '2026-07-14', '2026-07-14',  0.00),
  (2, 1, 'Paris Fashion Week',     'Showcase of haute couture collections.',
       '2026-09-28', '2026-10-06', 75.00),
  (3, 2, 'Cherry Blossom Festival','Hanami parties in Ueno Park & beyond.',
       '2026-03-25', '2026-04-10', 10.00),
  (4, 2, 'Sumida River Fireworks', 'Tokyo''s biggest summer fireworks show.',
       '2026-07-25', '2026-07-25',  0.00),
  (5, 3, 'Cairo International Film Festival', 'Egypt''s premier film festival.',
       '2025-11-12', '2025-11-21', 35.00),
  (6, 3, 'Abu Simbel Sun Festival', 'Sun aligns with the inner sanctuary.',
       '2026-02-22', '2026-02-22', 25.00),
  (7,  4, 'Bali Arts Festival',         'Month-long celebration of dance and music.',
       '2026-06-13', '2026-07-11', 15.00),
  (8,  4, 'Nyepi (Day of Silence)',     'Balinese New Year, an island-wide quiet day.',
       '2026-03-19', '2026-03-19',  0.00),
  (9,  5, 'La Mercè Festival',          'Barcelona''s biggest street festival.',
       '2026-09-19', '2026-09-24',  0.00),
  (10, 5, 'Primavera Sound',            'Major international music festival.',
       '2026-05-28', '2026-05-31',195.00),
  (11, 6, 'Reykjavik Winter Lights',    'City-wide light installations + concerts.',
       '2026-02-05', '2026-02-08', 25.00),
  (12, 6, 'Iceland Airwaves',           'Annual showcase of Icelandic and global music.',
       '2026-11-04', '2026-11-07',155.00),
  (13, 7, 'Songkran Water Festival',    'Thai New Year — citywide water fights.',
       '2026-04-13', '2026-04-15',  0.00),
  (14, 7, 'Loy Krathong',               'Floating-lantern festival on rivers and ponds.',
       '2026-11-04', '2026-11-04',  0.00),
  (15, 8, 'NYC Marathon',               'World''s largest marathon, all five boroughs.',
       '2026-11-01', '2026-11-01',358.00),
  (16, 8, 'Tribeca Film Festival',      'Annual independent film celebration.',
       '2026-06-03', '2026-06-14', 25.00);

INSERT INTO accommodation (accommodation_id, destination_id, name, cost_per_night) VALUES
  (1, 1, 'Hotel Le Marais',          180.00),
  (2, 1, 'Boutique Latin Quarter',   145.00),
  (3, 2, 'Shinjuku Capsule Inn',      75.00),
  (4, 2, 'Park Hyatt Tokyo',         420.00),
  (5, 3, 'Marriott Mena House',      210.00),
  (6, 3, 'Cairo Downtown Hostel',     35.00),
  (7,  4, 'Ubud Jungle Villa',       125.00),
  (8,  4, 'Seminyak Beach Resort',   240.00),
  (9,  5, 'Hotel Casa Bonay',        195.00),
  (10, 5, 'Gothic Quarter Hostel',    45.00),
  (11, 6, 'Kvosin Downtown Hotel',   220.00),
  (12, 6, 'Reykjavik Loft Hostel',    60.00),
  (13, 7, 'Mandarin Oriental BKK',   380.00),
  (14, 7, 'Sukhumvit Boutique',       55.00),
  (15, 8, 'The Standard High Line',  340.00),
  (16, 8, 'Pod 51 Hotel',             95.00);

-- =====================================================================
-- STAND-ALONE FIX-IT STATEMENTS
-- =====================================================================
-- If you don't want to re-import the whole file and just want to patch
-- an existing database, run the statements below in phpMyAdmin.
-- =====================================================================

-- FIX 3A — drop the orphan column (run once):
-- ALTER TABLE itinerary_activity DROP COLUMN activity_entry_id;

-- FIX 3B — patch attraction ratings + categories on a live DB:
-- UPDATE attraction SET category='Landmark',   rating=4.8 WHERE name='Eiffel Tower';
-- UPDATE attraction SET category='Museum',     rating=4.7 WHERE name='Louvre Museum';
-- UPDATE attraction SET category='Experience', rating=4.5 WHERE name='Seine River Cruise';
-- UPDATE attraction SET category='Landmark',   rating=4.3 WHERE name='Tokyo Skytree';
-- UPDATE attraction SET category='Nature',     rating=4.6 WHERE name='Shinjuku Gyoen Park';
-- UPDATE attraction SET category='Experience', rating=4.9 WHERE name='TeamLab Borderless';
-- UPDATE attraction SET category='Landmark',   rating=4.9 WHERE name='Pyramids of Giza';
-- UPDATE attraction SET category='Museum',     rating=4.4 WHERE name='Egyptian Museum';
-- UPDATE attraction SET category='Experience', rating=4.6 WHERE name='Nile River Cruise';

-- FIX 3C — patch event dates on a live DB:
-- UPDATE event SET start_date='2026-07-14', end_date='2026-07-14' WHERE name='Bastille Day Fireworks';
-- UPDATE event SET start_date='2026-09-28', end_date='2026-10-06' WHERE name='Paris Fashion Week';
-- UPDATE event SET start_date='2026-03-25', end_date='2026-04-10' WHERE name='Cherry Blossom Festival';
-- UPDATE event SET start_date='2026-07-25', end_date='2026-07-25' WHERE name='Sumida River Fireworks';
-- UPDATE event SET start_date='2025-11-12', end_date='2025-11-21' WHERE name='Cairo International Film Festival';
-- UPDATE event SET start_date='2026-02-22', end_date='2026-02-22' WHERE name='Abu Simbel Sun Festival';

-- v3 — add the 5 new destinations (Bali, Barcelona, Reykjavik, Bangkok, NYC)
-- + their attractions, events, and accommodations to a LIVE database
-- without reimporting. Safe to re-run thanks to INSERT IGNORE.
--
-- INSERT IGNORE INTO destination (destination_id, city, country, climate, visa_required, image_url) VALUES
--   (4,'Bali','Indonesia','Hot','No','https://images.unsplash.com/photo-1537996194471-e657df975ab4?auto=compress&cs=tinysrgb&w=1200'),
--   (5,'Barcelona','Spain','Mild','No','https://images.unsplash.com/photo-1583422409516-2895a77efded?auto=compress&cs=tinysrgb&w=1200'),
--   (6,'Reykjavik','Iceland','Cold','No','https://images.unsplash.com/photo-1504284769763-88de03e6e6e9?auto=compress&cs=tinysrgb&w=1200'),
--   (7,'Bangkok','Thailand','Hot','No','https://images.unsplash.com/photo-1508009603885-50cf7c579365?auto=compress&cs=tinysrgb&w=1200'),
--   (8,'New York','USA','Seasonal','Yes','https://images.unsplash.com/photo-1496442226666-8d4d0e62e6e9?auto=compress&cs=tinysrgb&w=1200');
--
-- INSERT IGNORE INTO attraction (attraction_id, destination_id, name, category, rating, entry_fee, avg_time_hours) VALUES
--   (10,4,'Uluwatu Temple','Landmark',4.7,12.00,2.0),
--   (11,4,'Tegallalang Rice Terraces','Nature',4.6,5.00,2.5),
--   (12,4,'Sacred Monkey Forest','Nature',4.4,7.00,1.5),
--   (13,5,'Sagrada Familia','Landmark',4.9,26.00,2.0),
--   (14,5,'Park Güell','Nature',4.6,10.00,2.0),
--   (15,5,'Picasso Museum','Museum',4.5,14.00,2.0),
--   (16,6,'Blue Lagoon','Experience',4.7,95.00,3.0),
--   (17,6,'Hallgrimskirkja','Landmark',4.6,5.00,1.0),
--   (18,6,'Northern Lights Tour','Experience',4.8,120.00,4.0),
--   (19,7,'Grand Palace','Landmark',4.7,16.00,2.5),
--   (20,7,'Wat Pho Temple','Landmark',4.6,6.00,1.5),
--   (21,7,'Chatuchak Weekend Market','Experience',4.5,0.00,3.0),
--   (22,8,'Statue of Liberty','Landmark',4.7,24.00,3.0),
--   (23,8,'Metropolitan Museum','Museum',4.8,30.00,3.0),
--   (24,8,'Top of the Rock','Experience',4.7,40.00,1.5);
--
-- INSERT IGNORE INTO event (event_id, destination_id, name, description, start_date, end_date, price) VALUES
--   (7,4,'Bali Arts Festival','Month-long celebration of dance and music.','2026-06-13','2026-07-11',15.00),
--   (8,4,'Nyepi (Day of Silence)','Balinese New Year, an island-wide quiet day.','2026-03-19','2026-03-19',0.00),
--   (9,5,'La Mercè Festival','Barcelona''s biggest street festival.','2026-09-19','2026-09-24',0.00),
--   (10,5,'Primavera Sound','Major international music festival.','2026-05-28','2026-05-31',195.00),
--   (11,6,'Reykjavik Winter Lights','City-wide light installations + concerts.','2026-02-05','2026-02-08',25.00),
--   (12,6,'Iceland Airwaves','Annual showcase of Icelandic and global music.','2026-11-04','2026-11-07',155.00),
--   (13,7,'Songkran Water Festival','Thai New Year — citywide water fights.','2026-04-13','2026-04-15',0.00),
--   (14,7,'Loy Krathong','Floating-lantern festival on rivers and ponds.','2026-11-04','2026-11-04',0.00),
--   (15,8,'NYC Marathon','World''s largest marathon, all five boroughs.','2026-11-01','2026-11-01',358.00),
--   (16,8,'Tribeca Film Festival','Annual independent film celebration.','2026-06-03','2026-06-14',25.00);
--
-- INSERT IGNORE INTO accommodation (accommodation_id, destination_id, name, cost_per_night) VALUES
--   (7,4,'Ubud Jungle Villa',125.00),(8,4,'Seminyak Beach Resort',240.00),
--   (9,5,'Hotel Casa Bonay',195.00),(10,5,'Gothic Quarter Hostel',45.00),
--   (11,6,'Kvosin Downtown Hotel',220.00),(12,6,'Reykjavik Loft Hostel',60.00),
--   (13,7,'Mandarin Oriental BKK',380.00),(14,7,'Sukhumvit Boutique',55.00),
--   (15,8,'The Standard High Line',340.00),(16,8,'Pod 51 Hotel',95.00);

-- v2 — add the dates/budget/sharing/soft-delete columns to a live DB
-- (safe to run; will fail with "duplicate column" if already applied):
-- ALTER TABLE itinerary ADD COLUMN start_date  DATE NULL AFTER destination_id;
-- ALTER TABLE itinerary ADD COLUMN budget      DECIMAL(10,2) DEFAULT 0 AFTER start_date;
-- ALTER TABLE itinerary ADD COLUMN deleted_at  DATETIME NULL DEFAULT NULL;
-- ALTER TABLE itinerary ADD COLUMN share_token CHAR(32) NULL DEFAULT NULL UNIQUE;

-- FIX 3D — backfill destination image URLs on a live DB:
-- UPDATE destination
--    SET image_url='https://images.unsplash.com/photo-1502602898657-3e91760cbb34?auto=compress&cs=tinysrgb&w=1200'
--  WHERE city='Paris';
-- UPDATE destination
--    SET image_url='https://media.istockphoto.com/id/1390815938/photo/tokyo-city-in-japan.jpg?s=612x612&w=0&k=20&c=VHiC3TlbXkb-Yf6WUYjh825Y0nGMCTkNUa9j8X8rVfY='
--  WHERE city='Tokyo';
-- UPDATE destination
--    SET image_url='https://media.gettyimages.com/id/1404746517/photo/bedouins-and-pyramids.jpg?s=612x612&w=0&k=20&c=OAwETyeofhQ_SFoRfps2b-7zPDKGSHff94ch-ZzIWD0='
--  WHERE city='Cairo';
