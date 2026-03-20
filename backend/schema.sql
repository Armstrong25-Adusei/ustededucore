-- EduCore Database Schema v5.5
-- Create this database and run this migration to set up the application

-- ══════════════════════════════════════════════════════════════════════════════
-- STUDENTS TABLE
-- ══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `students` (
  `student_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `master_list_id` INT UNSIGNED NULL,
  `lecturer_id` INT UNSIGNED NULL,
  `index_number` VARCHAR(50) UNIQUE NOT NULL,
  `student_name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) UNIQUE NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `enrollment_status` ENUM('pending', 'active', 'suspended') DEFAULT 'pending',
  `account_status` ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
  `registered_by` VARCHAR(100),
  `device_uuid` VARCHAR(36) UNIQUE NULL,
  `generated_id` VARCHAR(64) NULL COMMENT 'Verification token or password reset token',
  `enrollment_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_email` (`email`),
  KEY `idx_index_number` (`index_number`),
  KEY `idx_enrollment_status` (`enrollment_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════════════════════════════════════════════
-- INSTITUTIONS TABLE (for public search)
-- ══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `institutions` (
  `institution_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `code` VARCHAR(50) UNIQUE,
  `country` VARCHAR(100),
  `city` VARCHAR(100),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════════════════════════════════════════════
-- LECTURERS TABLE (placeholder for future expansion)
-- ══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `lecturers` (
  `lecturer_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) UNIQUE NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(255) NOT NULL,
  `institution_id` INT UNSIGNED,
  `account_status` ENUM('active', 'inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`institution_id`) REFERENCES `institutions`(`institution_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════════════════════════════════════════════
-- DIRECT MESSAGES TABLE (lecturer ↔ student DMs)
-- ══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `dm_messages` (
  `message_id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `dm_id` BIGINT UNSIGNED NOT NULL COMMENT 'Synthetic ID: 3000000000 + student_id',
  `lecturer_id` INT UNSIGNED NOT NULL,
  `student_id` INT UNSIGNED NOT NULL,
  `sender_type` ENUM('lecturer', 'student') NOT NULL,
  `sender_id` INT UNSIGNED NOT NULL,
  `body` TEXT NOT NULL CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `deleted_by` VARCHAR(20),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_dm_id` (`dm_id`),
  KEY `idx_lecturer_student` (`lecturer_id`, `student_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
