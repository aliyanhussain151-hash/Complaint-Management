-- =====================================================================
-- Migration 001 : complaint evidence, closure notifications, status history
--
-- HOW TO RUN (preferred, includes guarded ALTERs + backfill):
--     php tools/migrate.php
--     php tools/migrate.php --dry-run
--     php tools/migrate.php --database=USP_dryrun
--
-- This file is the reference DDL. Every statement is guarded with
-- CREATE TABLE IF NOT EXISTS, so running it twice is harmless.
--
-- The two ALTER TABLE statements that add the columns the messaging code
-- already expects (`replies.is_read`, `replies.receiver_id`) and the
-- backfill of complaint_status_history are done by tools/migrate.php,
-- because plain ALTER TABLE is not idempotent in MariaDB 10.4 and the
-- backfill must never fabricate rows.
--
-- BEFORE RUNNING: take a backup
--     C:\xampp\mysql\bin\mysqldump.exe -u root --databases usp \
--         --single-transaction --result-file=..\backups\usp_backup.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Evidence / attachments. No equivalent table existed (checked:
--    attachments, files, documents, uploads -> none), so this is new.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaint_attachments` (
  `attachment_id` int(11) NOT NULL AUTO_INCREMENT,
  `complaint_id` int(11) NOT NULL,
  `stored_name` varchar(128) NOT NULL COMMENT 'random server-side filename, never user supplied',
  `original_name` varchar(255) NOT NULL COMMENT 'sanitised client filename, metadata only',
  `mime_type` varchar(100) NOT NULL COMMENT 'server-detected content type',
  `file_size` int(10) unsigned NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attachment_id`),
  UNIQUE KEY `uq_attachment_stored_name` (`stored_name`),
  KEY `idx_attachment_complaint` (`complaint_id`),
  KEY `idx_attachment_uploader` (`uploaded_by`),
  CONSTRAINT `fk_attachment_complaint` FOREIGN KEY (`complaint_id`)
    REFERENCES `complaints` (`complaint_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attachment_uploader` FOREIGN KEY (`uploaded_by`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 2. Notifications. No equivalent table existed (no notification/message
--    table of any kind was present), so this is new. In-app delivery only.
--
--    uq_notification_dedupe makes closure notification idempotent: a
--    double click / retry cannot insert a second row for the same
--    complaint + type + recipient.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'recipient',
  `complaint_id` int(11) DEFAULT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'complaint_closed',
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  UNIQUE KEY `uq_notification_dedupe` (`complaint_id`,`type`,`user_id`),
  KEY `idx_notification_user` (`user_id`,`is_read`,`created_at`),
  KEY `idx_notification_complaint` (`complaint_id`),
  CONSTRAINT `fk_notification_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notification_complaint` FOREIGN KEY (`complaint_id`)
    REFERENCES `complaints` (`complaint_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 3. Status history / timeline. Checked first: admin_logs, staff_logs and
--    user_activity_logs exist but none of them records complaint status
--    transitions with (complaint_id, event, timestamp), so a dedicated
--    history table is required.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaint_status_history` (
  `history_id` int(11) NOT NULL AUTO_INCREMENT,
  `complaint_id` int(11) NOT NULL,
  `event_type` varchar(50) NOT NULL COMMENT 'submitted|assigned|reassigned|unassigned|status_changed|closed',
  `status` varchar(20) DEFAULT NULL COMMENT 'complaints.status after the event, NULL for non-status events',
  `message` varchar(500) DEFAULT NULL,
  `actor_id` int(11) DEFAULT NULL COMMENT 'NULL = system / legacy backfill',
  `actor_role` varchar(20) DEFAULT NULL COMMENT 'role captured at write time',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`history_id`),
  KEY `idx_history_complaint_created` (`complaint_id`,`created_at`),
  KEY `idx_history_actor` (`actor_id`),
  CONSTRAINT `fk_history_complaint` FOREIGN KEY (`complaint_id`)
    REFERENCES `complaints` (`complaint_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_history_actor` FOREIGN KEY (`actor_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
