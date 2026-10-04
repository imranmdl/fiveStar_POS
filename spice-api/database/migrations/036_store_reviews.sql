-- Store reviews: a customer's star rating and comment about the SHOP as a
-- whole, left from the storefront home page. Kept apart from `product_reviews`,
-- which are about one product and need a delivered order behind them.
--
-- Anyone can leave one, so nothing is published until staff approve it.
CREATE TABLE IF NOT EXISTS `store_reviews` (
    `id`               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `uuid`             CHAR(36)         NOT NULL,
    `reviewer_name`    VARCHAR(120)     NOT NULL,
    `reviewer_mobile`  VARCHAR(15)      NULL,
    `rating`           TINYINT UNSIGNED NOT NULL,
    `body`             VARCHAR(1000)    NULL,
    `status`           ENUM('pending','approved','rejected','hidden') NOT NULL DEFAULT 'pending',
    `moderated_by`     BIGINT UNSIGNED  NULL,
    `moderated_date`   DATETIME         NULL,
    `moderation_note`  VARCHAR(500)     NULL,
    `merchant_reply`   VARCHAR(1000)    NULL,
    `created_date`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `is_deleted`       TINYINT(1)       NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_store_reviews_uuid` (`uuid`),
    KEY `idx_store_reviews_status` (`status`, `created_date`),
    CONSTRAINT `chk_store_reviews_rating` CHECK (`rating` BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('036_store_reviews', 36, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
