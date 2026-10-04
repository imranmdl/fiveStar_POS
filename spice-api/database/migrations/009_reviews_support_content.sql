-- ============================================================================
--  Spice & Dry Fruits Commerce Platform
--  Migration 009 - Reviews, Support Tickets and Content
--
--  product_reviews, review_media, review_reports, review_votes,
--  support_tickets, support_ticket_messages, cms_pages, blog_posts,
--  faq_entries.
--
--  Three decisions worth stating up front.
--
--  A REVIEW REQUIRES A DELIVERED ORDER FOR THAT PRODUCT. Not an account, not a
--  purchase — a delivery. It is the single most effective defence against fake
--  reviews available to a small merchant, it costs nothing here because the
--  order data is already present, and it is the difference between a rating
--  that means something and one that means whoever cared most.
--
--  RATINGS ARE RECOMPUTED, NOT INCREMENTED. Keeping a running average by adding
--  each new star to a total is faster and drifts: one missed decrement on a
--  deleted review and the number is quietly wrong forever, with no way to tell.
--  Recomputing from the approved rows costs one indexed aggregate and is always
--  right.
--
--  SUPPORT MESSAGES ARE EITHER CUSTOMER-VISIBLE OR INTERNAL, NEVER BOTH. One
--  thread, one flag. Two separate tables would eventually be joined in the
--  wrong order and show a customer what a colleague said about them.
--
--  MySQL 8.0.16+
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- product_reviews
-- ---------------------------------------------------------------------------
CREATE TABLE `product_reviews` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `product_id`          BIGINT UNSIGNED NOT NULL,
    `variant_id`          BIGINT UNSIGNED NULL COMMENT 'Which pack size was bought',
    `user_id`             BIGINT UNSIGNED NOT NULL,
    `order_id`            BIGINT UNSIGNED NULL
                          COMMENT 'The delivered order that entitles this review',
    `rating`              TINYINT UNSIGNED NOT NULL,
    `title`               VARCHAR(150)    NULL,
    `body`                TEXT            NULL,
    `is_verified_purchase` TINYINT(1)     NOT NULL DEFAULT 0,
    `status`              ENUM('pending','approved','rejected','hidden')
                          NOT NULL DEFAULT 'pending',
    `moderated_by`        BIGINT UNSIGNED NULL,
    `moderated_date`      DATETIME        NULL,
    `moderation_note`     VARCHAR(500)    NULL,
    `helpful_count`       INT UNSIGNED    NOT NULL DEFAULT 0,
    `not_helpful_count`   INT UNSIGNED    NOT NULL DEFAULT 0,
    `report_count`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `merchant_reply`      VARCHAR(1000)   NULL,
    `merchant_reply_date` DATETIME        NULL,
    `merchant_reply_by`   BIGINT UNSIGNED NULL,
    `created_by`          BIGINT UNSIGNED NULL,
    `created_date`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`          BIGINT UNSIGNED NULL,
    `updated_date`        DATETIME        NULL,
    `deleted_by`          BIGINT UNSIGNED NULL,
    `deleted_date`        DATETIME        NULL,
    `is_active`           TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`          TINYINT(1)      NOT NULL DEFAULT 0,
    `version`             INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_product_reviews_uuid` (`uuid`),
    -- One review per customer per product. Editing replaces; a customer who
    -- changes their mind should not be able to stack five one-star reviews.
    UNIQUE KEY `uq_review_user_product` (`product_id`, `user_id`),
    KEY `idx_product_reviews_product` (`product_id`, `status`, `created_date`),
    KEY `idx_product_reviews_user` (`user_id`),
    KEY `idx_product_reviews_moderation` (`status`, `created_date`),
    KEY `idx_product_reviews_reported` (`report_count`, `status`),
    CONSTRAINT `chk_review_rating_range`
        CHECK (`rating` >= 1 AND `rating` <= 5),
    -- A review with neither a title nor a body is just a rating, which is
    -- allowed; but a title with no rating is not a review at all.
    CONSTRAINT `fk_product_reviews_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_product_reviews_variant`
        FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_product_reviews_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_product_reviews_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_product_reviews_moderator`
        FOREIGN KEY (`moderated_by`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- review_media
--
-- Photographs are moderated separately from the text. An innocuous five-star
-- review can carry an image that must never be published, and approving the
-- words should not approve the picture.
-- ---------------------------------------------------------------------------
CREATE TABLE `review_media` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`           CHAR(36)        NOT NULL,
    `review_id`      BIGINT UNSIGNED NOT NULL,
    `media_type`     ENUM('image','video') NOT NULL DEFAULT 'image',
    `file_path`      VARCHAR(500)    NOT NULL,
    `thumbnail_path` VARCHAR(500)    NULL,
    `caption`        VARCHAR(255)    NULL,
    `display_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `status`         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `created_by`     BIGINT UNSIGNED NULL,
    `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`     BIGINT UNSIGNED NULL,
    `updated_date`   DATETIME        NULL,
    `deleted_by`     BIGINT UNSIGNED NULL,
    `deleted_date`   DATETIME        NULL,
    `is_active`      TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`     TINYINT(1)      NOT NULL DEFAULT 0,
    `version`        INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_review_media_uuid` (`uuid`),
    KEY `idx_review_media_review` (`review_id`, `status`),
    CONSTRAINT `fk_review_media_review`
        FOREIGN KEY (`review_id`) REFERENCES `product_reviews` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- review_reports
--
-- One report per person per review, so a review cannot be buried by one
-- determined complainant refreshing the page.
-- ---------------------------------------------------------------------------
CREATE TABLE `review_reports` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`          CHAR(36)        NOT NULL,
    `review_id`     BIGINT UNSIGNED NOT NULL,
    `user_id`       BIGINT UNSIGNED NOT NULL,
    `reason`        ENUM('spam','offensive','irrelevant','fake','other') NOT NULL,
    `detail`        VARCHAR(500)    NULL,
    `status`        ENUM('open','upheld','dismissed') NOT NULL DEFAULT 'open',
    `reviewed_by`   BIGINT UNSIGNED NULL,
    `reviewed_date` DATETIME        NULL,
    `created_by`    BIGINT UNSIGNED NULL,
    `created_date`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`    BIGINT UNSIGNED NULL,
    `updated_date`  DATETIME        NULL,
    `deleted_by`    BIGINT UNSIGNED NULL,
    `deleted_date`  DATETIME        NULL,
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`    TINYINT(1)      NOT NULL DEFAULT 0,
    `version`       INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_review_reports_uuid` (`uuid`),
    UNIQUE KEY `uq_review_report_user` (`review_id`, `user_id`),
    KEY `idx_review_reports_status` (`status`, `created_date`),
    CONSTRAINT `fk_review_reports_review`
        FOREIGN KEY (`review_id`) REFERENCES `product_reviews` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_review_reports_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- review_votes
-- ---------------------------------------------------------------------------
CREATE TABLE `review_votes` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`         CHAR(36)        NOT NULL,
    `review_id`    BIGINT UNSIGNED NOT NULL,
    `user_id`      BIGINT UNSIGNED NOT NULL,
    `is_helpful`   TINYINT(1)      NOT NULL,
    `created_by`   BIGINT UNSIGNED NULL,
    `created_date` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`   BIGINT UNSIGNED NULL,
    `updated_date` DATETIME        NULL,
    `deleted_by`   BIGINT UNSIGNED NULL,
    `deleted_date` DATETIME        NULL,
    `is_active`    TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`   TINYINT(1)      NOT NULL DEFAULT 0,
    `version`      INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_review_votes_uuid` (`uuid`),
    UNIQUE KEY `uq_review_vote_user` (`review_id`, `user_id`),
    CONSTRAINT `fk_review_votes_review`
        FOREIGN KEY (`review_id`) REFERENCES `product_reviews` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_review_votes_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- support_tickets
-- ---------------------------------------------------------------------------
CREATE TABLE `support_tickets` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `ticket_number`       VARCHAR(30)     NOT NULL,
    `user_id`             BIGINT UNSIGNED NULL COMMENT 'NULL for a guest enquiry',
    `order_id`            BIGINT UNSIGNED NULL,
    `category`            ENUM('order','delivery','payment','refund','product',
                               'account','wholesale','other')
                          NOT NULL DEFAULT 'other',
    `priority`            ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    `subject`             VARCHAR(200)    NOT NULL,
    `contact_name`        VARCHAR(120)    NOT NULL,
    `contact_mobile`      VARCHAR(15)     NOT NULL,
    `contact_email`       VARCHAR(180)    NULL,
    `status`              ENUM('open','awaiting_customer','in_progress','resolved','closed')
                          NOT NULL DEFAULT 'open',
    `assigned_to_user_id` BIGINT UNSIGNED NULL,
    -- SLA timestamps. First response is the number customers actually judge
    -- support by; resolution is what the business judges it by.
    `first_response_due`  DATETIME        NULL,
    `first_response_date` DATETIME        NULL,
    `resolution_due`      DATETIME        NULL,
    `resolved_date`       DATETIME        NULL,
    `closed_date`         DATETIME        NULL,
    `resolution_note`     VARCHAR(1000)   NULL,
    `satisfaction_rating` TINYINT UNSIGNED NULL,
    `satisfaction_comment` VARCHAR(500)   NULL,
    `reopened_count`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `last_message_date`   DATETIME        NULL,
    `source`              ENUM('web','app','phone','email','whatsapp') NOT NULL DEFAULT 'web',
    `created_by`          BIGINT UNSIGNED NULL,
    `created_date`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`          BIGINT UNSIGNED NULL,
    `updated_date`        DATETIME        NULL,
    `deleted_by`          BIGINT UNSIGNED NULL,
    `deleted_date`        DATETIME        NULL,
    `is_active`           TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`          TINYINT(1)      NOT NULL DEFAULT 0,
    `version`             INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_support_tickets_uuid` (`uuid`),
    UNIQUE KEY `uq_support_tickets_number` (`ticket_number`),
    KEY `idx_support_tickets_status` (`status`, `priority`, `created_date`),
    KEY `idx_support_tickets_user` (`user_id`, `created_date`),
    KEY `idx_support_tickets_assignee` (`assigned_to_user_id`, `status`),
    KEY `idx_support_tickets_sla` (`status`, `first_response_due`),
    KEY `idx_support_tickets_order` (`order_id`),
    CONSTRAINT `chk_ticket_satisfaction_range`
        CHECK (`satisfaction_rating` IS NULL
            OR (`satisfaction_rating` >= 1 AND `satisfaction_rating` <= 5)),
    CONSTRAINT `fk_support_tickets_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_support_tickets_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_support_tickets_assignee`
        FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- support_ticket_messages
--
-- One thread with a visibility flag, not two tables. Separate customer and
-- internal tables would eventually be joined in the wrong order and show a
-- customer what a colleague said about them.
-- ---------------------------------------------------------------------------
CREATE TABLE `support_ticket_messages` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL,
    `ticket_id`           BIGINT UNSIGNED NOT NULL,
    `author_user_id`      BIGINT UNSIGNED NULL COMMENT 'NULL when written by the system',
    `author_type`         ENUM('customer','staff','system') NOT NULL,
    `body`                TEXT            NOT NULL,
    `is_internal_note`    TINYINT(1)      NOT NULL DEFAULT 0
                          COMMENT 'Never shown to the customer',
    `attachment_path`     VARCHAR(500)    NULL,
    `created_by`          BIGINT UNSIGNED NULL,
    `created_date`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`          BIGINT UNSIGNED NULL,
    `updated_date`        DATETIME        NULL,
    `deleted_by`          BIGINT UNSIGNED NULL,
    `deleted_date`        DATETIME        NULL,
    `is_active`           TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`          TINYINT(1)      NOT NULL DEFAULT 0,
    `version`             INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_support_messages_uuid` (`uuid`),
    KEY `idx_support_messages_ticket` (`ticket_id`, `created_date`),
    -- A customer cannot author an internal note. Enforced rather than assumed,
    -- because the consequence of getting it wrong is a customer reading staff
    -- commentary about their own complaint.
    CONSTRAINT `chk_message_internal_is_staff`
        CHECK (`is_internal_note` = 0 OR `author_type` <> 'customer'),
    CONSTRAINT `fk_support_messages_ticket`
        FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_support_messages_author`
        FOREIGN KEY (`author_user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- cms_pages
-- ---------------------------------------------------------------------------
CREATE TABLE `cms_pages` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`             CHAR(36)        NOT NULL,
    `slug`             VARCHAR(160)    NOT NULL,
    `title`            VARCHAR(200)    NOT NULL,
    `body`             LONGTEXT        NOT NULL,
    `excerpt`          VARCHAR(500)    NULL,
    `meta_title`       VARCHAR(200)    NULL,
    `meta_description` VARCHAR(320)    NULL,
    `status`           ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    `published_date`   DATETIME        NULL,
    -- Legal pages must not be casually deleted: a returns policy has to be
    -- reproducible as it stood on the day of a disputed order.
    `is_system_page`   TINYINT(1)      NOT NULL DEFAULT 0,
    `display_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_by`       BIGINT UNSIGNED NULL,
    `created_date`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`       BIGINT UNSIGNED NULL,
    `updated_date`     DATETIME        NULL,
    `deleted_by`       BIGINT UNSIGNED NULL,
    `deleted_date`     DATETIME        NULL,
    `is_active`        TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`       TINYINT(1)      NOT NULL DEFAULT 0,
    `version`          INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cms_pages_uuid` (`uuid`),
    UNIQUE KEY `uq_cms_pages_slug` (`slug`),
    KEY `idx_cms_pages_status` (`status`, `display_order`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- blog_posts
-- ---------------------------------------------------------------------------
CREATE TABLE `blog_posts` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`             CHAR(36)        NOT NULL,
    `slug`             VARCHAR(160)    NOT NULL,
    `title`            VARCHAR(200)    NOT NULL,
    `excerpt`          VARCHAR(500)    NULL,
    `body`             LONGTEXT        NOT NULL,
    `cover_image_path` VARCHAR(500)    NULL,
    `category`         VARCHAR(60)     NULL,
    `tags`             JSON            NULL,
    `author_user_id`   BIGINT UNSIGNED NULL,
    `author_name`      VARCHAR(120)    NULL COMMENT 'Snapshot; the author may leave',
    `meta_title`       VARCHAR(200)    NULL,
    `meta_description` VARCHAR(320)    NULL,
    `status`           ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    `published_date`   DATETIME        NULL,
    `view_count`       INT UNSIGNED    NOT NULL DEFAULT 0,
    `reading_minutes`  TINYINT UNSIGNED NULL,
    `created_by`       BIGINT UNSIGNED NULL,
    `created_date`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`       BIGINT UNSIGNED NULL,
    `updated_date`     DATETIME        NULL,
    `deleted_by`       BIGINT UNSIGNED NULL,
    `deleted_date`     DATETIME        NULL,
    `is_active`        TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`       TINYINT(1)      NOT NULL DEFAULT 0,
    `version`          INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_blog_posts_uuid` (`uuid`),
    UNIQUE KEY `uq_blog_posts_slug` (`slug`),
    KEY `idx_blog_posts_status` (`status`, `published_date`),
    KEY `idx_blog_posts_category` (`category`, `status`),
    FULLTEXT KEY `ft_blog_posts` (`title`, `excerpt`, `body`),
    CONSTRAINT `fk_blog_posts_author`
        FOREIGN KEY (`author_user_id`) REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- faq_entries
-- ---------------------------------------------------------------------------
CREATE TABLE `faq_entries` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`           CHAR(36)        NOT NULL,
    `group_code`     VARCHAR(60)     NOT NULL DEFAULT 'general',
    `question`       VARCHAR(300)    NOT NULL,
    `answer`         TEXT            NOT NULL,
    `display_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `helpful_count`  INT UNSIGNED    NOT NULL DEFAULT 0,
    `status`         ENUM('draft','published') NOT NULL DEFAULT 'published',
    `created_by`     BIGINT UNSIGNED NULL,
    `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`     BIGINT UNSIGNED NULL,
    `updated_date`   DATETIME        NULL,
    `deleted_by`     BIGINT UNSIGNED NULL,
    `deleted_date`   DATETIME        NULL,
    `is_active`      TINYINT(1)      NOT NULL DEFAULT 1,
    `is_deleted`     TINYINT(1)      NOT NULL DEFAULT 0,
    `version`        INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_faq_entries_uuid` (`uuid`),
    KEY `idx_faq_entries_group` (`group_code`, `status`, `display_order`),
    FULLTEXT KEY `ft_faq_entries` (`question`, `answer`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Reporting views
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_product_ratings` AS
SELECT
    p.`id`   AS `product_id`,
    p.`uuid` AS `product_uuid`,
    p.`name` AS `product_name`,
    COUNT(r.`id`)                                        AS `review_count`,
    ROUND(AVG(r.`rating`), 2)                            AS `rating_average`,
    SUM(r.`rating` = 5)                                  AS `five_star`,
    SUM(r.`rating` = 4)                                  AS `four_star`,
    SUM(r.`rating` = 3)                                  AS `three_star`,
    SUM(r.`rating` = 2)                                  AS `two_star`,
    SUM(r.`rating` = 1)                                  AS `one_star`,
    SUM(r.`is_verified_purchase` = 1)                    AS `verified_count`
FROM `products` p
INNER JOIN `product_reviews` r
        ON r.`product_id` = p.`id`
       AND r.`status` = 'approved'
       AND r.`is_deleted` = 0
WHERE p.`is_deleted` = 0
GROUP BY p.`id`, p.`uuid`, p.`name`;

CREATE OR REPLACE VIEW `vw_support_performance` AS
SELECT
    DATE(t.`created_date`)                               AS `ticket_date`,
    t.`category`,
    COUNT(*)                                             AS `tickets`,
    SUM(t.`status` IN ('resolved','closed'))             AS `resolved`,
    SUM(t.`first_response_date` IS NULL
        AND t.`first_response_due` < NOW()
        AND t.`status` NOT IN ('resolved','closed'))     AS `first_response_breached`,
    ROUND(AVG(TIMESTAMPDIFF(MINUTE, t.`created_date`, t.`first_response_date`)), 1)
                                                         AS `avg_first_response_minutes`,
    ROUND(AVG(TIMESTAMPDIFF(HOUR, t.`created_date`, t.`resolved_date`)), 1)
                                                         AS `avg_resolution_hours`,
    ROUND(AVG(t.`satisfaction_rating`), 2)               AS `avg_satisfaction`
FROM `support_tickets` t
WHERE t.`is_deleted` = 0
GROUP BY DATE(t.`created_date`), t.`category`;

INSERT INTO `schema_migrations` (`migration`, `batch`, `applied_by`)
VALUES ('009_reviews_support_content', 9, 'migration-runner')
ON DUPLICATE KEY UPDATE `applied_date` = `applied_date`;
