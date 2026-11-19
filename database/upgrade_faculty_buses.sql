USE bus_attendance;
ALTER TABLE students MODIFY bus_status ENUM('Active','Inactive','Not available') NOT NULL DEFAULT 'Inactive';
CREATE TABLE IF NOT EXISTS buses (
 bus_no VARCHAR(50) PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO buses (bus_no) SELECT DISTINCT bus_no FROM students WHERE bus_no IS NOT NULL AND bus_no<>'';
CREATE TABLE IF NOT EXISTS faculty_access (
 faculty_id VARCHAR(64) COLLATE utf8mb4_bin PRIMARY KEY,
 access_enabled TINYINT(1) NOT NULL DEFAULT 1,
 bus_no VARCHAR(50) NULL,
 FOREIGN KEY (bus_no) REFERENCES buses(bus_no) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
