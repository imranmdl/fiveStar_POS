-- ============================================================================
--  Rollback for migration 009 - Reviews, Support Tickets and Content
--
--  Destroys customer reviews and the whole support history. Export first:
--    mysqldump <db> product_reviews support_tickets support_ticket_messages > engagement.sql
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW IF EXISTS `vw_support_performance`;
DROP VIEW IF EXISTS `vw_product_ratings`;

-- Reset the denormalised aggregates on products. Leaving a rating average
-- behind with no reviews to support it would show customers a score derived
-- from nothing.
UPDATE `products`
   SET `rating_average` = 0, `rating_count` = 0, `review_count` = 0
 WHERE `rating_count` > 0 OR `review_count` > 0;

DROP TABLE IF EXISTS `faq_entries`;
DROP TABLE IF EXISTS `blog_posts`;
DROP TABLE IF EXISTS `cms_pages`;
DROP TABLE IF EXISTS `support_ticket_messages`;
DROP TABLE IF EXISTS `support_tickets`;
DROP TABLE IF EXISTS `review_votes`;
DROP TABLE IF EXISTS `review_reports`;
DROP TABLE IF EXISTS `review_media`;
DROP TABLE IF EXISTS `product_reviews`;

DELETE FROM `schema_migrations` WHERE `migration` = '009_reviews_support_content';

SET FOREIGN_KEY_CHECKS = 1;
