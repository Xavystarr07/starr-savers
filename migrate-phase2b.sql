-- Import in phpMyAdmin (select your database -> Import). Safe to run more than once.
ALTER TABLE return_requests MODIFY status
  ENUM('requested','approved','pickup_scheduled','collected','received','refund_approved','refunded','rejected') NOT NULL DEFAULT 'requested';

CREATE TABLE IF NOT EXISTS return_logistics (
    return_id INT UNSIGNED NOT NULL,
    pickup_method ENUM('driver','dropoff') DEFAULT NULL,
    pickup_at DATETIME DEFAULT NULL,
    pickup_note VARCHAR(255) DEFAULT NULL,
    collected_at DATETIME DEFAULT NULL,
    collected_by VARCHAR(120) DEFAULT NULL,
    received_at DATETIME DEFAULT NULL,
    received_by VARCHAR(120) DEFAULT NULL,
    condition_status ENUM('good','damaged','not_returned') DEFAULT NULL,
    condition_note VARCHAR(255) DEFAULT NULL,
    outcome ENUM('full_refund','partial_refund','reject_return_goods') DEFAULT NULL,
    refund_amount DECIMAL(10,2) DEFAULT NULL,
    outcome_note VARCHAR(255) DEFAULT NULL,
    goods_returned_at DATETIME DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (return_id),
    CONSTRAINT fk_rl_return FOREIGN KEY (return_id) REFERENCES return_requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
