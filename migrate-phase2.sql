-- Import in phpMyAdmin (select your database -> Import). Safe to run more than once.
CREATE TABLE IF NOT EXISTS refunds (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    refund_number VARCHAR(20) NOT NULL,
    order_id INT UNSIGNED NOT NULL,
    return_id INT UNSIGNED DEFAULT NULL,
    method ENUM('paystack','eft') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    status ENUM('processing','processed','failed','needs_attention') NOT NULL DEFAULT 'processing',
    paystack_reference VARCHAR(80) DEFAULT NULL,
    paystack_refund_id VARCHAR(40) NOT NULL DEFAULT '',
    paystack_status VARCHAR(30) DEFAULT NULL,
    bank_reference VARCHAR(80) DEFAULT NULL,
    error_message VARCHAR(255) DEFAULT NULL,
    restock TINYINT(1) NOT NULL DEFAULT 0,
    notify_customer TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_refund_number (refund_number),
    KEY idx_refund_order (order_id),
    KEY idx_refund_status (status),
    CONSTRAINT fk_refund_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    topic VARCHAR(40) NOT NULL,
    order_number VARCHAR(30) DEFAULT NULL,
    message TEXT NOT NULL,
    is_done TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_contact_done (is_done)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
