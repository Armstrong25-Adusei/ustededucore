-- EduCore DM Tables Migration
-- Add support for lecturer-student direct messaging

-- ══════════════════════════════════════════════════════════════════════════════
-- TABLE: direct_message_threads
-- ──────────────────────────────────────────────────────────────────────────────
-- Stores conversation threads between lecturers and students.
-- Each thread is unique per (lecturer_id, student_id) pair.
-- ══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `direct_message_threads` (
  `thread_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `lecturer_id` INT UNSIGNED NOT NULL,
  `student_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_message` LONGTEXT,
  `last_time` TIMESTAMP NULL,
  `unread_count_lecturer` INT UNSIGNED DEFAULT 0,
  `unread_count_student` INT UNSIGNED DEFAULT 0,
  UNIQUE KEY `uq_thread` (`lecturer_id`, `student_id`),
  KEY `idx_lecturer` (`lecturer_id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════════════════════════════════════════════
-- TABLE: direct_messages
-- ──────────────────────────────────────────────────────────────────────────────
-- Individual messages within DM threads.
-- ══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `direct_messages` (
  `dm_id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `thread_id` INT UNSIGNED NOT NULL,
  `sender_type` ENUM('lecturer', 'student') NOT NULL,
  `sender_id` INT UNSIGNED NOT NULL,
  `body` LONGTEXT NOT NULL CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `parent_id` BIGINT UNSIGNED,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `deleted_by` VARCHAR(20),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_thread` (`thread_id`),
  KEY `idx_sender` (`sender_type`, `sender_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_parent` (`parent_id`),
  CONSTRAINT `fk_dm_thread` FOREIGN KEY (`thread_id`)
    REFERENCES `direct_message_threads` (`thread_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════════════════════════════════════════════
-- TABLE: dm_reads
-- ──────────────────────────────────────────────────────────────────────────────
-- Tracks which messages have been read by whom.
-- ══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `dm_reads` (
  `read_id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `dm_id` BIGINT UNSIGNED NOT NULL,
  `reader_type` ENUM('lecturer', 'student') NOT NULL,
  `reader_id` INT UNSIGNED NOT NULL,
  `read_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_dm_read` (`dm_id`, `reader_type`, `reader_id`),
  KEY `idx_dm_id` (`dm_id`),
  KEY `idx_reader` (`reader_type`, `reader_id`),
  CONSTRAINT `fk_dm_reads` FOREIGN KEY (`dm_id`)
    REFERENCES `direct_messages` (`dm_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════════════════════════════════════════════
-- TABLE: dm_reactions
-- ──────────────────────────────────────────────────────────────────────────────
-- Emoji reactions on DM messages (like "❤️", "😂", etc.)
-- ══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `dm_reactions` (
  `reaction_id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `dm_id` BIGINT UNSIGNED NOT NULL,
  `reactor_type` ENUM('lecturer', 'student') NOT NULL,
  `reactor_id` INT UNSIGNED NOT NULL,
  `emoji` VARCHAR(8) NOT NULL CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_dm_reaction` (`dm_id`, `reactor_type`, `reactor_id`, `emoji`),
  KEY `idx_dm_id` (`dm_id`),
  KEY `idx_reactor` (`reactor_type`, `reactor_id`),
  CONSTRAINT `fk_dm_reactions` FOREIGN KEY (`dm_id`)
    REFERENCES `direct_messages` (`dm_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
