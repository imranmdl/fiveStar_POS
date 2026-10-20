-- Rollback 065: default back to reviewed. Flags set by 065 stay (harmless:
-- an administrator can mark the items reviewed).
ALTER TABLE `products`
    MODIFY COLUMN `admin_reviewed` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '0 = waiting for an administrator to check the details';

DELETE FROM `schema_migrations` WHERE `migration` = '065_review_flag_accuracy';
