CREATE DATABASE IF NOT EXISTS bus_attendance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bus_attendance;

CREATE TABLE IF NOT EXISTS students (
 student_id VARCHAR(64) COLLATE utf8mb4_bin PRIMARY KEY,
 reg_no VARCHAR(100) NOT NULL,
 student_name VARCHAR(255) NOT NULL,
 year VARCHAR(100) NULL,
 dept VARCHAR(150) NOT NULL,
 bus_no VARCHAR(50) NULL,
 drop_point VARCHAR(150) NULL,
 drop_point_status ENUM('Active','Inactive') NOT NULL DEFAULT 'Inactive',
 bus_status ENUM('Active','Inactive','Not available') NOT NULL DEFAULT 'Inactive',
 INDEX idx_reg (reg_no), INDEX idx_active (bus_status,drop_point_status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 student_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 attendance_date DATE NOT NULL,
 attendance_status ENUM('Present','Absent') NOT NULL,
 staff_user_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 UNIQUE KEY uq_student_date (student_id, attendance_date),
 INDEX idx_date (attendance_date), INDEX idx_staff_date (staff_user_id,attendance_date),
 FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admins (
 id TINYINT UNSIGNED PRIMARY KEY,
 username VARCHAR(100) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 CONSTRAINT single_initial_admin CHECK (id = 1)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
 attempt_key CHAR(64) PRIMARY KEY,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 window_start DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS buses (
 bus_no VARCHAR(50) PRIMARY KEY,
 driver_name VARCHAR(150) NOT NULL DEFAULT '',
 driver_phone VARCHAR(25) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO buses (bus_no) SELECT DISTINCT bus_no FROM students WHERE bus_no IS NOT NULL AND bus_no<>'';
CREATE TABLE IF NOT EXISTS faculty_access (
 faculty_id VARCHAR(64) COLLATE utf8mb4_bin PRIMARY KEY,
 access_enabled TINYINT(1) NOT NULL DEFAULT 1,
 bus_no VARCHAR(50) NULL,
 FOREIGN KEY (bus_no) REFERENCES buses(bus_no) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bus_drop_points (
 bus_no VARCHAR(50) NOT NULL,
 position TINYINT UNSIGNED NOT NULL,
 drop_point VARCHAR(150) NOT NULL,
 PRIMARY KEY (bus_no,position),
 FOREIGN KEY (bus_no) REFERENCES buses(bus_no) ON UPDATE CASCADE ON DELETE CASCADE,
 CONSTRAINT ten_drop_points CHECK (position BETWEEN 1 AND 10)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
