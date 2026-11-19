-- ============================================================================
-- STARR SAVERS — database.sql
-- Target: MySQL 5.7+ / MariaDB 10.3+ (InfinityFree shared hosting)
--
-- HOW TO IMPORT (InfinityFree):
--   1. Create the database in the InfinityFree control panel (MySQL Databases).
--   2. Open phpMyAdmin, select that database, then use the "Import" tab.
--   3. Do NOT add CREATE DATABASE / USE statements — InfinityFree does not allow them.
--
-- All money is stored in ZAR (South African Rand), VAT-INCLUSIVE (15%).
-- Weights are stored in kilograms.
-- Sample admin login:  admin@starrsavers.co.za  /  ChangeMe!2026   (CHANGE IT IMMEDIATELY)
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+02:00';
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS cart_items;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS delivery_weight_tiers;
DROP TABLE IF EXISTS delivery_zones;
DROP TABLE IF EXISTS branches;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS site_settings;

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------------
-- USERS
-- ----------------------------------------------------------------------------
CREATE TABLE users (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name         VARCHAR(120)  NOT NULL,
    email             VARCHAR(190)  NOT NULL,
    phone             VARCHAR(25)   DEFAULT NULL,
    password_hash     VARCHAR(255)  NOT NULL,
    role              ENUM('customer','admin') NOT NULL DEFAULT 'customer',
    address_line1     VARCHAR(190)  DEFAULT NULL,
    address_line2     VARCHAR(190)  DEFAULT NULL,
    suburb            VARCHAR(100)  DEFAULT NULL,
    city              VARCHAR(100)  DEFAULT NULL,
    province          VARCHAR(60)   DEFAULT NULL,
    postal_code       VARCHAR(10)   DEFAULT NULL,
    latitude          DECIMAL(9,6)  DEFAULT NULL,
    longitude         DECIMAL(9,6)  DEFAULT NULL,
    popia_consent_at  DATETIME      DEFAULT NULL,          -- when the customer accepted the privacy notice
    marketing_opt_in  TINYINT(1)    NOT NULL DEFAULT 0,    -- POPIA: explicit opt-in only
    is_active         TINYINT(1)    NOT NULL DEFAULT 1,
    failed_logins     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until      DATETIME      DEFAULT NULL,
    last_login_at     DATETIME      DEFAULT NULL,
    created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  CHAR(64)     NOT NULL,                     -- SHA-256 of the emailed token
    expires_at  DATETIME     NOT NULL,
    used_at     DATETIME     DEFAULT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pr_token (token_hash),
    CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- BRANCHES (dark stores / pick-and-pack hubs that deliveries leave from)
-- ----------------------------------------------------------------------------
CREATE TABLE branches (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name             VARCHAR(120) NOT NULL,
    slug             VARCHAR(120) NOT NULL,
    address_line1    VARCHAR(190) NOT NULL,
    suburb           VARCHAR(100) NOT NULL,
    city             VARCHAR(100) NOT NULL,
    province         VARCHAR(60)  NOT NULL,
    postal_code      VARCHAR(10)  DEFAULT NULL,
    latitude         DECIMAL(9,6) NOT NULL,
    longitude        DECIMAL(9,6) NOT NULL,
    phone            VARCHAR(25)  DEFAULT NULL,
    email            VARCHAR(190) DEFAULT NULL,
    opening_hours    VARCHAR(190) DEFAULT 'Mon-Sun 08:00 - 20:00',
    bike_max_km      DECIMAL(5,2) NOT NULL DEFAULT 10.00,  -- beyond this, bike is unavailable
    car_max_km       DECIMAL(5,2) NOT NULL DEFAULT 20.00,  -- beyond this, we do not deliver
    offers_collection TINYINT(1)  NOT NULL DEFAULT 1,
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_branch_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- DELIVERY PRICING
--   Fee = zone fee (by distance from branch, per vehicle) + weight-tier surcharge.
--   The weight tier decides the vehicle: tiers marked 'car' force car delivery.
--   bike_fee NULL = bike not offered in that zone.
-- ----------------------------------------------------------------------------
CREATE TABLE delivery_zones (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    zone_name   VARCHAR(60)   NOT NULL,
    min_km      DECIMAL(5,2)  NOT NULL,
    max_km      DECIMAL(5,2)  NOT NULL,
    bike_fee    DECIMAL(8,2)  DEFAULT NULL,
    car_fee     DECIMAL(8,2)  NOT NULL,
    sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_active   TINYINT(1)    NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_zone_range (min_km, max_km)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE delivery_weight_tiers (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tier_name   VARCHAR(60)   NOT NULL,
    min_kg      DECIMAL(8,2)  NOT NULL,
    max_kg      DECIMAL(8,2)  NOT NULL,
    vehicle     ENUM('bike','car') NOT NULL,
    surcharge   DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
    sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_tier_range (min_kg, max_kg)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- CATEGORIES
--   Flash Deals is "virtual": it lists any product with is_flash_deal = 1.
-- ----------------------------------------------------------------------------
CREATE TABLE categories (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(80)  NOT NULL,
    slug        VARCHAR(80)  NOT NULL,
    icon        VARCHAR(30)  DEFAULT NULL,                 -- icon key used by the front end
    is_virtual  TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cat_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- PRODUCTS
-- ----------------------------------------------------------------------------
CREATE TABLE products (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id      INT UNSIGNED NOT NULL,
    sku              VARCHAR(40)  NOT NULL,
    name             VARCHAR(160) NOT NULL,
    slug             VARCHAR(180) NOT NULL,
    brand            VARCHAR(80)  DEFAULT NULL,
    short_desc       VARCHAR(255) DEFAULT NULL,
    description      TEXT         DEFAULT NULL,
    unit_label       VARCHAR(30)  DEFAULT NULL,            -- e.g. "5kg", "750ml", "18 rolls"
    price            DECIMAL(10,2) NOT NULL,               -- VAT-inclusive selling price
    compare_at_price DECIMAL(10,2) DEFAULT NULL,           -- "was" price for deals
    weight_kg        DECIMAL(8,3) NOT NULL DEFAULT 0.500,  -- shipping weight per unit
    stock_qty        INT          NOT NULL DEFAULT 0,
    low_stock_at     INT          NOT NULL DEFAULT 5,
    sizes            VARCHAR(120) DEFAULT NULL,            -- comma list for clothing, e.g. "S,M,L,XL"
    image_url        VARCHAR(255) DEFAULT NULL,
    is_premium       TINYINT(1)   NOT NULL DEFAULT 0,
    is_flash_deal    TINYINT(1)   NOT NULL DEFAULT 0,
    deal_ends_at     DATETIME     DEFAULT NULL,
    is_featured      TINYINT(1)   NOT NULL DEFAULT 0,
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_product_sku (sku),
    UNIQUE KEY uq_product_slug (slug),
    KEY idx_product_cat (category_id, is_active),
    KEY idx_product_flags (is_flash_deal, is_premium, is_featured),
    FULLTEXT KEY ft_product_search (name, brand, short_desc),
    CONSTRAINT fk_product_cat FOREIGN KEY (category_id) REFERENCES categories (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- CART (server-side copy for signed-in customers; guests use localStorage)
-- ----------------------------------------------------------------------------
CREATE TABLE cart_items (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    product_id  INT UNSIGNED NOT NULL,
    size        VARCHAR(20)  NOT NULL DEFAULT '',
    quantity    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    added_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cart_line (user_id, product_id, size),
    CONSTRAINT fk_cart_user    FOREIGN KEY (user_id)    REFERENCES users (id)    ON DELETE CASCADE,
    CONSTRAINT fk_cart_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- ORDERS
-- ----------------------------------------------------------------------------
CREATE TABLE orders (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_number         VARCHAR(20)  NOT NULL,            -- e.g. SS-20261114-0001
    user_id              INT UNSIGNED DEFAULT NULL,        -- NULL = guest checkout
    branch_id            INT UNSIGNED DEFAULT NULL,

    customer_name        VARCHAR(120) NOT NULL,
    customer_email       VARCHAR(190) NOT NULL,
    customer_phone       VARCHAR(25)  NOT NULL,

    delivery_method      ENUM('bike','car','collect') NOT NULL DEFAULT 'bike',
    delivery_address1    VARCHAR(190) DEFAULT NULL,
    delivery_address2    VARCHAR(190) DEFAULT NULL,
    delivery_suburb      VARCHAR(100) DEFAULT NULL,
    delivery_city        VARCHAR(100) DEFAULT NULL,
    delivery_province    VARCHAR(60)  DEFAULT NULL,
    delivery_postal_code VARCHAR(10)  DEFAULT NULL,
    delivery_latitude    DECIMAL(9,6) DEFAULT NULL,
    delivery_longitude   DECIMAL(9,6) DEFAULT NULL,
    delivery_distance_km DECIMAL(6,2) DEFAULT NULL,
    delivery_notes       VARCHAR(255) DEFAULT NULL,
    total_weight_kg      DECIMAL(8,2) NOT NULL DEFAULT 0.00,

    subtotal             DECIMAL(10,2) NOT NULL,           -- items, VAT-inclusive
    delivery_fee         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_amount      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    vat_amount           DECIMAL(10,2) NOT NULL DEFAULT 0.00,  -- VAT contained in the total
    total                DECIMAL(10,2) NOT NULL,

    payment_method       ENUM('paystack','eft') NOT NULL,
    payment_status       ENUM('pending','awaiting_verification','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    paystack_reference   VARCHAR(80)  DEFAULT NULL,
    paystack_paid_at     DATETIME     DEFAULT NULL,
    eft_reference        VARCHAR(60)  DEFAULT NULL,        -- reference the customer must use at the bank
    pop_file_path        VARCHAR(255) DEFAULT NULL,        -- /uploads/pop/<file> (proof of payment)
    pop_uploaded_at      DATETIME     DEFAULT NULL,

    status               ENUM('pending','confirmed','packing','out_for_delivery','delivered','ready_for_collection','collected','cancelled','refunded')
                         NOT NULL DEFAULT 'pending',
    admin_notes          TEXT         DEFAULT NULL,
    terms_accepted_at    DATETIME     DEFAULT NULL,        -- ECTA / CPA: customer accepted T&Cs
    created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_order_number (order_number),
    UNIQUE KEY uq_paystack_ref (paystack_reference),
    KEY idx_orders_user (user_id),
    KEY idx_orders_status (status, payment_status),
    KEY idx_orders_created (created_at),
    CONSTRAINT fk_orders_user   FOREIGN KEY (user_id)   REFERENCES users (id)    ON DELETE SET NULL,
    CONSTRAINT fk_orders_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id      INT UNSIGNED NOT NULL,
    product_id    INT UNSIGNED DEFAULT NULL,
    sku           VARCHAR(40)   NOT NULL,                  -- snapshot at time of purchase
    product_name  VARCHAR(160)  NOT NULL,
    size          VARCHAR(20)   NOT NULL DEFAULT '',
    unit_price    DECIMAL(10,2) NOT NULL,
    quantity      SMALLINT UNSIGNED NOT NULL,
    weight_kg     DECIMAL(8,3)  NOT NULL DEFAULT 0.000,
    line_total    DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_oi_order (order_id),
    CONSTRAINT fk_oi_order   FOREIGN KEY (order_id)   REFERENCES orders (id)   ON DELETE CASCADE,
    CONSTRAINT fk_oi_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- SITE SETTINGS (key/value, editable from /admin/settings.php)
-- ----------------------------------------------------------------------------
CREATE TABLE site_settings (
    setting_key    VARCHAR(80)  NOT NULL,
    setting_value  TEXT         DEFAULT NULL,
    setting_group  VARCHAR(40)  NOT NULL DEFAULT 'general',
    label          VARCHAR(120) DEFAULT NULL,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SAMPLE DATA
-- ============================================================================

-- Admin account. Password: ChangeMe!2026  (bcrypt) — change after first login.
INSERT INTO users (full_name, email, phone, password_hash, role, popia_consent_at, is_active) VALUES
('Starr Savers Admin', 'admin@starrsavers.co.za', '0310000000',
 '$2b$10$shUmFvagKLU04.6Vdg7UN.GjS6.al6vjvYlxrz19iwuP.9IJHeNsa', 'admin', NOW(), 1);

-- Branches (sample KwaZulu-Natal hubs — replace with your real addresses/coordinates)
INSERT INTO branches (name, slug, address_line1, suburb, city, province, postal_code, latitude, longitude, phone, email, bike_max_km, car_max_km) VALUES
('Starr Savers Musgrave',  'musgrave',  '12 Musgrave Road',        'Musgrave',      'Durban',  'KwaZulu-Natal', '4001', -29.838600, 31.001200, '0310000001', 'musgrave@starrsavers.co.za',  10.00, 25.00),
('Starr Savers Umhlanga',  'umhlanga',  '5 Lagoon Drive',          'Umhlanga Ridge','Umhlanga','KwaZulu-Natal', '4319', -29.726600, 31.063500, '0310000002', 'umhlanga@starrsavers.co.za',  10.00, 25.00),
('Starr Savers Pinetown',  'pinetown',  '88 Josiah Gumede Road',   'Pinetown',      'Pinetown','KwaZulu-Natal', '3610', -29.816700, 30.866700, '0310000003', 'pinetown@starrsavers.co.za',   8.00, 22.00),
('Starr Savers Westville', 'westville', '21 Jan Hofmeyr Road',     'Westville',     'Westville','KwaZulu-Natal','3629', -29.833300, 30.933300, '0310000004', 'westville@starrsavers.co.za',  10.00, 22.00);

-- Delivery zones (distance from the fulfilling branch)
INSERT INTO delivery_zones (zone_name, min_km, max_km, bike_fee, car_fee, sort_order) VALUES
('Zone A — Nearby',   0.00,  3.00, 25.00,  49.00, 1),
('Zone B — Local',    3.00,  6.00, 35.00,  69.00, 2),
('Zone C — Extended', 6.00, 10.00, 49.00,  99.00, 3),
('Zone D — Far',     10.00, 15.00, NULL,  149.00, 4),
('Zone E — Outer',   15.00, 25.00, NULL,  199.00, 5);

-- Weight tiers: light loads ride on bikes; heavy/bulk loads auto-switch to car
INSERT INTO delivery_weight_tiers (tier_name, min_kg, max_kg, vehicle, surcharge, sort_order) VALUES
('Light',   0.00,   8.00, 'bike',  0.00, 1),
('Medium',  8.01,  15.00, 'bike', 15.00, 2),
('Heavy',  15.01,  40.00, 'car',   0.00, 3),
('Bulk',   40.01, 150.00, 'car',  60.00, 4);

-- Categories
INSERT INTO categories (id, name, slug, icon, is_virtual, sort_order) VALUES
(1, 'Groceries & Pantry',   'groceries-pantry',   'basket',  0, 1),
(2, 'Everyday Essentials',  'everyday-essentials','home',    0, 2),
(3, 'Clothing & Apparel',   'clothing-apparel',   'shirt',   0, 3),
(4, 'Premium Selection',    'premium-selection',  'crown',   0, 4),
(5, 'Flash Deals',          'flash-deals',        'bolt',    1, 5);

-- Products — Groceries & Pantry
INSERT INTO products (category_id, sku, name, slug, brand, short_desc, unit_label, price, compare_at_price, weight_kg, stock_qty, image_url, is_featured, is_flash_deal, deal_ends_at) VALUES
(1,'GRO-0001','White Star Super Maize Meal 5kg','white-star-super-maize-meal-5kg','White Star','Everyday staple for pap — smooth and fine.','5kg',64.99,NULL,5.000,120,'/uploads/products/white-star-maize-meal-5kg.jpg',1,0,NULL),
(1,'GRO-0002','Tastic Long Grain Parboiled Rice 2kg','tastic-long-grain-rice-2kg','Tastic','Fluffy, separate grains every time.','2kg',49.99,NULL,2.000,150,'/uploads/products/tastic-rice-2kg.jpg',1,0,NULL),
(1,'GRO-0003','Sasko Cake Wheat Flour 2.5kg','sasko-cake-flour-2-5kg','Sasko','Fine cake flour for baking and pastries.','2.5kg',42.99,NULL,2.500,90,'/uploads/products/sasko-cake-flour-2-5kg.jpg',0,0,NULL),
(1,'GRO-0004','Selati White Sugar 2.5kg','selati-white-sugar-2-5kg','Selati','Pure refined white sugar.','2.5kg',54.99,NULL,2.500,110,'/uploads/products/selati-sugar-2-5kg.jpg',0,0,NULL),
(1,'GRO-0005','Sunfoil Sunflower Cooking Oil 2L','sunfoil-cooking-oil-2l','Sunfoil','Light, golden oil for frying and baking.','2L',89.99,99.99,1.900,70,'/uploads/products/sunfoil-oil-2l.jpg',0,1,DATE_ADD(NOW(), INTERVAL 3 DAY)),
(1,'GRO-0006','Koo Baked Beans in Tomato Sauce 410g','koo-baked-beans-410g','Koo','Hearty beans in a rich tomato sauce.','410g',16.99,NULL,0.440,300,'/uploads/products/koo-baked-beans-410g.jpg',0,0,NULL),
(1,'GRO-0007','Lucky Star Pilchards in Tomato Sauce 400g','lucky-star-pilchards-400g','Lucky Star','Protein-rich pilchards in tomato sauce.','400g',24.99,NULL,0.430,260,'/uploads/products/lucky-star-pilchards-400g.jpg',0,0,NULL),
(1,'GRO-0008','Parmalat Full Cream Long Life Milk 1L','parmalat-full-cream-milk-1l','Parmalat','UHT full cream milk — no fridge needed until opened.','1L',22.99,NULL,1.050,200,'/uploads/products/parmalat-milk-1l.jpg',0,0,NULL),
(1,'GRO-0009','Albany Best of Both White Bread 700g','albany-best-of-both-700g','Albany','Soft sliced loaf with added fibre.','700g',19.99,NULL,0.720,60,'/uploads/products/albany-bread-700g.jpg',0,0,NULL),
(1,'GRO-0010','Kellogg''s Corn Flakes 750g','kelloggs-corn-flakes-750g','Kellogg''s','Crunchy golden flakes for a quick breakfast.','750g',64.99,74.99,0.800,85,'/uploads/products/kelloggs-corn-flakes-750g.jpg',0,1,DATE_ADD(NOW(), INTERVAL 2 DAY)),
(1,'GRO-0011','Joko Black Tea Bags 100s','joko-tea-bags-100s','Joko','Strong, full-flavoured everyday tea.','100 bags',42.99,NULL,0.250,140,'/uploads/products/joko-tea-100s.jpg',0,0,NULL),
(1,'GRO-0012','All Gold Tomato Sauce 700ml','all-gold-tomato-sauce-700ml','All Gold','South Africa''s favourite tomato sauce.','700ml',39.99,NULL,0.800,130,'/uploads/products/all-gold-tomato-sauce-700ml.jpg',0,0,NULL),
(1,'GRO-0013','Rama Original Margarine Tub 500g','rama-original-margarine-500g','Rama','Smooth and spreadable, great on bread and in baking.','500g',38.99,NULL,0.520,95,'/uploads/products/rama-margarine-500g.jpg',0,0,NULL);

-- Products — Everyday Essentials
INSERT INTO products (category_id, sku, name, slug, brand, short_desc, unit_label, price, compare_at_price, weight_kg, stock_qty, image_url, is_featured, is_flash_deal, deal_ends_at) VALUES
(2,'ESS-0001','Sunlight Dishwashing Liquid Lemon 750ml','sunlight-dishwashing-liquid-750ml','Sunlight','Cuts through grease, gentle on hands.','750ml',32.99,NULL,0.800,180,'/uploads/products/sunlight-dishwashing-750ml.jpg',0,0,NULL),
(2,'ESS-0002','OMO Auto Washing Powder 2kg','omo-auto-washing-powder-2kg','OMO','Tough on stains in machine washes.','2kg',109.99,129.99,2.050,80,'/uploads/products/omo-auto-2kg.jpg',1,1,DATE_ADD(NOW(), INTERVAL 4 DAY)),
(2,'ESS-0003','Twinsaver Toilet Paper 2-Ply 18 Pack','twinsaver-toilet-paper-18-pack','Twinsaver','Soft 2-ply rolls in a value pack.','18 rolls',99.99,NULL,2.300,75,'/uploads/products/twinsaver-18-pack.jpg',0,0,NULL),
(2,'ESS-0004','Colgate Total Toothpaste 100ml','colgate-total-toothpaste-100ml','Colgate','12-hour germ protection and fresh breath.','100ml',32.99,NULL,0.150,160,'/uploads/products/colgate-total-100ml.jpg',0,0,NULL),
(2,'ESS-0005','Dettol Antiseptic Liquid 500ml','dettol-antiseptic-liquid-500ml','Dettol','Trusted protection against germs.','500ml',69.99,NULL,0.560,90,'/uploads/products/dettol-500ml.jpg',0,0,NULL),
(2,'ESS-0006','Vaseline Original Petroleum Jelly 250ml','vaseline-petroleum-jelly-250ml','Vaseline','Locks in moisture for dry skin.','250ml',42.99,NULL,0.290,120,'/uploads/products/vaseline-250ml.jpg',0,0,NULL),
(2,'ESS-0007','Domestos Thick Bleach Original 750ml','domestos-thick-bleach-750ml','Domestos','Kills 99.9% of germs, clings to surfaces.','750ml',39.99,NULL,0.850,100,'/uploads/products/domestos-750ml.jpg',0,0,NULL);

-- Products — Clothing & Apparel (sizes stored as a comma list)
INSERT INTO products (category_id, sku, name, slug, brand, short_desc, unit_label, price, compare_at_price, weight_kg, stock_qty, sizes, image_url, is_featured, is_flash_deal, deal_ends_at) VALUES
(3,'CLO-0001','Men''s Classic Crew Neck T-Shirt','mens-classic-crew-t-shirt','Starr Basics','100% combed cotton tee, relaxed everyday fit.','1 piece',129.99,NULL,0.250,200,'S,M,L,XL,XXL','/uploads/products/mens-crew-tee.jpg',1,0,NULL),
(3,'CLO-0002','Ladies Stretch Skinny Jeans','ladies-stretch-skinny-jeans','Starr Denim','High-stretch denim that moves with you.','1 piece',349.99,429.99,0.650,60,'28,30,32,34,36','/uploads/products/ladies-skinny-jeans.jpg',0,1,DATE_ADD(NOW(), INTERVAL 3 DAY)),
(3,'CLO-0003','Kids School Golf Shirt','kids-school-golf-shirt','Starr Kids','Hard-wearing golf shirt, perfect for school.','1 piece',89.99,NULL,0.200,150,'6,8,10,12,14','/uploads/products/kids-golf-shirt.jpg',0,0,NULL),
(3,'CLO-0004','Unisex Fleece Hoodie','unisex-fleece-hoodie','Starr Basics','Warm brushed fleece with kangaroo pocket.','1 piece',299.99,NULL,0.700,85,'S,M,L,XL','/uploads/products/unisex-fleece-hoodie.jpg',1,0,NULL),
(3,'CLO-0005','Cotton Rich Socks 3-Pack','cotton-rich-socks-3-pack','Starr Basics','Breathable cushioned-sole socks.','3 pairs',79.99,99.99,0.180,220,'One Size','/uploads/products/cotton-socks-3-pack.jpg',0,1,DATE_ADD(NOW(), INTERVAL 2 DAY)),
(3,'CLO-0006','Men''s Chino Shorts','mens-chino-shorts','Starr Basics','Smart-casual cotton chino shorts.','1 piece',199.99,NULL,0.400,70,'30,32,34,36,38','/uploads/products/mens-chino-shorts.jpg',0,0,NULL);

-- Products — Premium Selection
INSERT INTO products (category_id, sku, name, slug, brand, short_desc, unit_label, price, compare_at_price, weight_kg, stock_qty, image_url, is_premium, is_featured) VALUES
(4,'PRM-0001','Cold-Pressed Extra Virgin Olive Oil 500ml','cold-pressed-extra-virgin-olive-oil-500ml','Starr Reserve','Fruity, peppery oil from a single estate.','500ml',189.99,NULL,0.900,40,'/uploads/products/evoo-500ml.jpg',1,1),
(4,'PRM-0002','Free-Range Beef Biltong Slices 250g','free-range-beef-biltong-250g','Starr Reserve','Traditionally spiced and air-dried, sliced thin.','250g',119.99,NULL,0.270,55,'/uploads/products/biltong-250g.jpg',1,1),
(4,'PRM-0003','Single-Origin Coffee Beans 1kg','single-origin-coffee-beans-1kg','Starr Reserve','Medium roast with notes of cocoa and caramel.','1kg',329.99,NULL,1.050,30,'/uploads/products/coffee-beans-1kg.jpg',1,0),
(4,'PRM-0004','True Wireless Earbuds with Charging Case','true-wireless-earbuds','Starr Tech','Bluetooth 5.3, 24-hour battery with case.','1 set',599.99,799.99,0.200,25,'/uploads/products/wireless-earbuds.jpg',1,0),
(4,'PRM-0005','Genuine Leather Bifold Wallet','genuine-leather-bifold-wallet','Starr Leather','Slim wallet with RFID protection.','1 piece',449.99,NULL,0.150,35,'/uploads/products/leather-wallet.jpg',1,0);

-- Premium item that is also a flash deal
UPDATE products SET is_flash_deal = 1, deal_ends_at = DATE_ADD(NOW(), INTERVAL 2 DAY) WHERE sku = 'PRM-0004';

-- Site settings
INSERT INTO site_settings (setting_key, setting_value, setting_group, label) VALUES
('store_name',               'Starr Savers',                         'general',  'Store name'),
('store_tagline',            'Fast. Fair. Delivered.',               'general',  'Tagline'),
('store_email',              'hello@starrsavers.co.za',              'general',  'Customer care email'),
('store_phone',              '031 000 0000',                         'general',  'Customer care phone'),
('currency_symbol',          'R',                                    'general',  'Currency symbol'),
('vat_rate',                 '15',                                   'tax',      'VAT rate (%)'),
('vat_number',               '4000000000',                           'tax',      'VAT registration number'),
('legal_company_name',       'Starr Savers (Pty) Ltd',               'legal',    'Registered company name (ECTA s43)'),
('legal_reg_number',         '2026/000000/07',                       'legal',    'Company registration number'),
('legal_physical_address',   '12 Musgrave Road, Musgrave, Durban, 4001, KwaZulu-Natal', 'legal', 'Physical address (ECTA s43)'),
('legal_information_officer','Information Officer',                  'legal',    'POPIA Information Officer'),
('legal_information_email',  'privacy@starrsavers.co.za',            'legal',    'POPIA Information Officer email'),
('returns_window_days',      '7',                                    'legal',    'Cooling-off / returns window (days)'),
('free_delivery_threshold',  '600.00',                               'delivery', 'Free delivery above (ZAR, 0 = off)'),
('min_order_value',          '100.00',                               'delivery', 'Minimum order value (ZAR)'),
('bike_max_weight_kg',       '15',                                   'delivery', 'Max cart weight for bike delivery (kg)'),
('delivery_hours',           'Mon-Sun 08:00 - 20:00',                'delivery', 'Delivery hours'),
('eft_bank_name',            'FNB',                                  'payments', 'EFT bank name'),
('eft_account_name',         'Starr Savers (Pty) Ltd',               'payments', 'EFT account holder'),
('eft_account_number',       '62000000000',                          'payments', 'EFT account number'),
('eft_branch_code',          '250655',                               'payments', 'EFT branch code'),
('eft_account_type',         'Business Cheque',                      'payments', 'EFT account type'),
('eft_hold_hours',           '48',                                   'payments', 'Hours an unpaid EFT order is held'),
('paystack_enabled',         '1',                                    'payments', 'Enable Paystack'),
('eft_enabled',              '1',                                    'payments', 'Enable manual EFT'),
('maintenance_mode',         '0',                                    'general',  'Maintenance mode (1 = on)');
