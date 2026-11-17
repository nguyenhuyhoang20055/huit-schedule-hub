CREATE DATABASE IF NOT EXISTS huit_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE huit_db;

CREATE TABLE IF NOT EXISTS branches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_name VARCHAR(120) NOT NULL,
  address VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS teachers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(30) NOT NULL,
  expertise VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin', 'teacher') NOT NULL,
  teacher_id INT UNSIGNED NULL UNIQUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_teacher FOREIGN KEY (teacher_id)
    REFERENCES teachers (id) ON DELETE CASCADE,
  CONSTRAINT chk_user_role_teacher CHECK (
    (role = 'teacher' AND teacher_id IS NOT NULL)
    OR (role = 'admin' AND teacher_id IS NULL)
  )
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS branch_travel_times (
  from_branch_id INT UNSIGNED NOT NULL,
  to_branch_id INT UNSIGNED NOT NULL,
  travel_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (from_branch_id, to_branch_id),
  CONSTRAINT fk_travel_from FOREIGN KEY (from_branch_id)
    REFERENCES branches (id) ON DELETE CASCADE,
  CONSTRAINT fk_travel_to FOREIGN KEY (to_branch_id)
    REFERENCES branches (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS schedules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  subject_name VARCHAR(180) NOT NULL,
  room VARCHAR(80) NOT NULL,
  day_of_week TINYINT UNSIGNED NOT NULL COMMENT '1=Thứ 2, 7=Chủ nhật',
  start_period TINYINT UNSIGNED NOT NULL,
  end_period TINYINT UNSIGNED NOT NULL,
  shift_or_periods VARCHAR(40) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_schedules_teacher FOREIGN KEY (teacher_id)
    REFERENCES teachers (id) ON DELETE RESTRICT,
  CONSTRAINT fk_schedules_branch FOREIGN KEY (branch_id)
    REFERENCES branches (id) ON DELETE RESTRICT,
  CONSTRAINT chk_schedule_day CHECK (day_of_week BETWEEN 1 AND 7),
  CONSTRAINT chk_schedule_periods CHECK (
    start_period BETWEEN 1 AND 12
    AND end_period BETWEEN start_period AND 12
  ),
  INDEX idx_schedule_teacher_day (teacher_id, day_of_week, start_period, end_period),
  INDEX idx_schedule_branch_day (branch_id, day_of_week)
) ENGINE=InnoDB;
