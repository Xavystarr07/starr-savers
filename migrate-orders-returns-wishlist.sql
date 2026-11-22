-- Import in phpMyAdmin (select your database -> Import). Safe to run more than once.
CREATE TABLE IF NOT EXISTS wishlist_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wish (user_id, product_id),
    CONSTRAINT fk_wish_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_wish_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS return_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_number VARCHAR(20) NOT NULL,
    order_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    customer_email VARCHAR(190) NOT NULL,
    status ENUM('requested','approved','received','refunded','rejected') NOT NULL DEFAULT 'requested',
    reason VARCHAR(30) NOT NULL,
    notes TEXT DEFAULT NULL,
    admin_notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_return_number (request_number),
    KEY idx_return_status (status),
    CONSTRAINT fk_return_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_return_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS return_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    return_id INT UNSIGNED NOT NULL,
    order_item_id INT UNSIGNED NOT NULL,
    quantity SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ri_return (return_id),
    CONSTRAINT fk_ri_return FOREIGN KEY (return_id) REFERENCES return_requests (id) ON DELETE CASCADE,
    CONSTRAINT fk_ri_item FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
