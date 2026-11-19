-- Attence table: single mutable store for the four-table cloud layout.
-- Run once against the erp database on TiDB Cloud.
-- The three ERP tables (erp_class, erp_student, erp_faculty) are pre-existing
-- read-only reference tables in the same database.
--
-- IMPORTANT: Do not run against a populated database.  Idempotent only on a
-- fresh target; existing Attence rows must survive schema-only re-runs.

USE `erp`;

CREATE TABLE IF NOT EXISTS `Attence` (
  `record_id`   BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `record_type` VARCHAR(32)      NOT NULL
                COMMENT 'Logical entity: students | attendance | admins | buses | bus_drop_points | faculty_access | login_attempts',
  `record_key`  CHAR(64)         NOT NULL
                COMMENT 'SHA-256 hex of the primary-key fields; computed by config.php::record_key()',
  `payload`     JSON             NOT NULL
                COMMENT 'Full record as JSON.  All reads and writes go through config.php helpers.',
  PRIMARY KEY (`record_id`),
  UNIQUE  KEY `uq_type_key`  (`record_type`, `record_key`),
  INDEX        `idx_type`     (`record_type`),
  -- Functional index lets MySQL/TiDB evaluate JSON predicates without a full scan.
  -- Attendance uniqueness: one row per student+date combination.
  INDEX        `idx_att_stu_date` (
      (CAST(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.student_id'))  AS CHAR(64))),
      (CAST(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.attendance_date')) AS DATE))
  )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Single mutable table for the bus-attendance application (four-table cloud layout).';
