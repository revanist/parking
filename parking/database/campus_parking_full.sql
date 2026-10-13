-- =========================================================
-- CampusPark - Full Database
-- Campus Parking Management System
-- MySQL / MariaDB / XAMPP
--
-- This replaces the old step1_step2.sql through step9_step15.sql
-- migrations with ONE clean importable database script.
--
-- IMPORTANT:
-- This script resets the campus_parking database tables/data.
-- Use it for a fresh development/test installation.
-- =========================================================

CREATE DATABASE IF NOT EXISTS campus_parking
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE campus_parking;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =========================================================
-- RESET EXISTING TABLES
-- =========================================================
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS violations;
DROP TABLE IF EXISTS parking_logs;
DROP TABLE IF EXISTS visitors;
DROP TABLE IF EXISTS parking_permits;
DROP TABLE IF EXISTS parking_slots;
DROP TABLE IF EXISTS parking_areas;
DROP TABLE IF EXISTS vehicles;
DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================
-- 1. USERS
-- =========================================================
CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    fullname VARCHAR(120) NOT NULL,
    username VARCHAR(50) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','security','faculty','student') NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    is_super_admin TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_role (role),
    KEY idx_users_status (status),
    KEY idx_users_super_admin (is_super_admin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 2. VEHICLES
-- =========================================================
CREATE TABLE vehicles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    plate_number VARCHAR(20) NOT NULL,
    vehicle_type ENUM('car','motorcycle','van','other') NOT NULL DEFAULT 'car',
    make VARCHAR(60) NOT NULL,
    model VARCHAR(60) NOT NULL,
    color VARCHAR(40) NOT NULL,
    vehicle_year YEAR NULL,
    photo_path VARCHAR(255) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_vehicle_plate (plate_number),
    KEY idx_vehicle_user (user_id),
    KEY idx_vehicle_status (status),

    CONSTRAINT fk_vehicle_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 3. PARKING AREAS
-- =========================================================
CREATE TABLE parking_areas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    area_name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    area_type ENUM('student','faculty','visitor','motorcycle','general') NOT NULL DEFAULT 'general',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_parking_area_name (area_name),
    KEY idx_area_type (area_type),
    KEY idx_area_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 4. PARKING SLOTS
-- =========================================================
CREATE TABLE parking_slots (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parking_area_id INT UNSIGNED NOT NULL,
    slot_code VARCHAR(30) NOT NULL,
    status ENUM('available','reserved','maintenance','inactive') NOT NULL DEFAULT 'available',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_slot_code (slot_code),
    KEY idx_slots_area (parking_area_id),
    KEY idx_slots_status (status),

    CONSTRAINT fk_slot_area
        FOREIGN KEY (parking_area_id) REFERENCES parking_areas(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 5. PARKING PERMITS
-- =========================================================
CREATE TABLE parking_permits (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    parking_slot_id INT UNSIGNED NULL,
    permit_number VARCHAR(40) NULL,
    qr_token CHAR(64) NULL,
    status ENUM('pending','approved','rejected','expired','cancelled') NOT NULL DEFAULT 'pending',
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    expires_at DATE NULL,
    rejection_reason VARCHAR(255) NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_permit_number (permit_number),
    UNIQUE KEY uq_permit_qr_token (qr_token),
    KEY idx_permit_user (user_id),
    KEY idx_permit_vehicle (vehicle_id),
    KEY idx_permit_slot (parking_slot_id),
    KEY idx_permit_status (status),
    KEY idx_permit_applied (applied_at),

    CONSTRAINT fk_permit_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_permit_vehicle
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_permit_slot
        FOREIGN KEY (parking_slot_id) REFERENCES parking_slots(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 6. VISITORS
-- Visitors do not need CampusPark user accounts.
-- =========================================================
CREATE TABLE visitors (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visitor_name VARCHAR(120) NOT NULL,
    contact VARCHAR(30) NULL,
    purpose VARCHAR(255) NULL,
    person_to_visit VARCHAR(120) NULL,
    vehicle_plate VARCHAR(20) NOT NULL,
    vehicle_type ENUM('car','motorcycle','van','other') NOT NULL DEFAULT 'car',
    parking_slot_id INT UNSIGNED NULL,
    status ENUM('inside','exited','cancelled') NOT NULL DEFAULT 'inside',
    entry_time DATETIME NULL,
    exit_time DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_visitor_plate (vehicle_plate),
    KEY idx_visitor_status (status),
    KEY idx_visitor_slot (parking_slot_id),
    KEY idx_visitor_entry (entry_time),

    CONSTRAINT fk_visitor_slot
        FOREIGN KEY (parking_slot_id) REFERENCES parking_slots(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 7. PARKING LOGS
-- One row represents one parking stay.
-- Registered vehicles use vehicle_id.
-- Visitors use visitor_id.
-- =========================================================
CREATE TABLE parking_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicle_id INT UNSIGNED NULL,
    visitor_id INT UNSIGNED NULL,
    parking_slot_id INT UNSIGNED NULL,
    entry_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    exit_time DATETIME NULL,
    recorded_by INT UNSIGNED NULL,
    status ENUM('inside','completed','cancelled') NOT NULL DEFAULT 'inside',
    notes VARCHAR(255) NULL,

    PRIMARY KEY (id),
    KEY idx_logs_vehicle (vehicle_id),
    KEY idx_logs_visitor (visitor_id),
    KEY idx_logs_slot (parking_slot_id),
    KEY idx_logs_recorder (recorded_by),
    KEY idx_logs_status (status),
    KEY idx_logs_entry (entry_time),

    CONSTRAINT fk_log_vehicle
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_log_visitor
        FOREIGN KEY (visitor_id) REFERENCES visitors(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_log_slot
        FOREIGN KEY (parking_slot_id) REFERENCES parking_slots(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_log_recorder
        FOREIGN KEY (recorded_by) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 8. VIOLATIONS
-- Can belong to a registered user/vehicle or a visitor.
-- =========================================================
CREATE TABLE violations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    vehicle_id INT UNSIGNED NULL,
    visitor_id INT UNSIGNED NULL,
    violation_type ENUM(
        'unauthorized_parking',
        'wrong_slot',
        'expired_permit',
        'restricted_area',
        'blocking',
        'other'
    ) NOT NULL,
    description VARCHAR(255) NULL,
    status ENUM('unresolved','resolved') NOT NULL DEFAULT 'unresolved',
    recorded_by INT UNSIGNED NULL,
    violation_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,

    PRIMARY KEY (id),
    KEY idx_violation_user (user_id),
    KEY idx_violation_vehicle (vehicle_id),
    KEY idx_violation_visitor (visitor_id),
    KEY idx_violation_recorder (recorded_by),
    KEY idx_violation_status (status),
    KEY idx_violation_date (violation_date),

    CONSTRAINT fk_violation_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_violation_vehicle
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_violation_visitor
        FOREIGN KEY (visitor_id) REFERENCES visitors(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_violation_recorder
        FOREIGN KEY (recorded_by) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 9. SYSTEM SETTINGS
-- =========================================================
CREATE TABLE system_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key VARCHAR(80) NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 10. AUDIT LOGS
-- =========================================================
CREATE TABLE audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    description VARCHAR(500) NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_audit_user (user_id),
    KEY idx_audit_action (action),
    KEY idx_audit_created (created_at),

    CONSTRAINT fk_audit_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 11. PASSWORD RESETS
-- =========================================================
CREATE TABLE password_resets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_pwd_reset_user (user_id),
    KEY idx_pwd_reset_token (token_hash),
    KEY idx_pwd_reset_expires (expires_at),

    CONSTRAINT fk_pwd_reset_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- DEFAULT SYSTEM SETTINGS
-- =========================================================
INSERT INTO system_settings (setting_key, setting_value) VALUES
('parking_open_time', '06:00'),
('parking_close_time', '22:00'),
('default_permit_months', '6'),
('system_name', 'CampusPark');

-- =========================================================
-- DEFAULT PARKING AREAS
-- =========================================================
INSERT INTO parking_areas (area_name, description, area_type) VALUES
('Student Parking', 'Main parking area for students.', 'student'),
('Faculty Parking', 'Reserved parking area for faculty members.', 'faculty'),
('Visitor Parking', 'Temporary parking for campus visitors.', 'visitor'),
('Motorcycle Parking', 'Designated motorcycle parking area.', 'motorcycle');

-- =========================================================
-- DEFAULT PARKING SLOTS
-- =========================================================
-- Student Parking: 10 slots (STU-01 through STU-10)
INSERT INTO parking_slots (parking_area_id, slot_code)
SELECT id, 'STU-01' FROM parking_areas WHERE area_name = 'Student Parking' UNION ALL
SELECT id, 'STU-02' FROM parking_areas WHERE area_name = 'Student Parking' UNION ALL
SELECT id, 'STU-03' FROM parking_areas WHERE area_name = 'Student Parking' UNION ALL
SELECT id, 'STU-04' FROM parking_areas WHERE area_name = 'Student Parking' UNION ALL
SELECT id, 'STU-05' FROM parking_areas WHERE area_name = 'Student Parking' UNION ALL
SELECT id, 'STU-06' FROM parking_areas WHERE area_name = 'Student Parking' UNION ALL
SELECT id, 'STU-07' FROM parking_areas WHERE area_name = 'Student Parking' UNION ALL
SELECT id, 'STU-08' FROM parking_areas WHERE area_name = 'Student Parking' UNION ALL
SELECT id, 'STU-09' FROM parking_areas WHERE area_name = 'Student Parking' UNION ALL
SELECT id, 'STU-10' FROM parking_areas WHERE area_name = 'Student Parking';

-- Faculty Parking: 10 slots (FAC-01 through FAC-10)
INSERT INTO parking_slots (parking_area_id, slot_code)
SELECT id, 'FAC-01' FROM parking_areas WHERE area_name = 'Faculty Parking' UNION ALL
SELECT id, 'FAC-02' FROM parking_areas WHERE area_name = 'Faculty Parking' UNION ALL
SELECT id, 'FAC-03' FROM parking_areas WHERE area_name = 'Faculty Parking' UNION ALL
SELECT id, 'FAC-04' FROM parking_areas WHERE area_name = 'Faculty Parking' UNION ALL
SELECT id, 'FAC-05' FROM parking_areas WHERE area_name = 'Faculty Parking' UNION ALL
SELECT id, 'FAC-06' FROM parking_areas WHERE area_name = 'Faculty Parking' UNION ALL
SELECT id, 'FAC-07' FROM parking_areas WHERE area_name = 'Faculty Parking' UNION ALL
SELECT id, 'FAC-08' FROM parking_areas WHERE area_name = 'Faculty Parking' UNION ALL
SELECT id, 'FAC-09' FROM parking_areas WHERE area_name = 'Faculty Parking' UNION ALL
SELECT id, 'FAC-10' FROM parking_areas WHERE area_name = 'Faculty Parking';

-- Visitor Parking: 5 slots (VIS-01 through VIS-05)
INSERT INTO parking_slots (parking_area_id, slot_code)
SELECT id, 'VIS-01' FROM parking_areas WHERE area_name = 'Visitor Parking' UNION ALL
SELECT id, 'VIS-02' FROM parking_areas WHERE area_name = 'Visitor Parking' UNION ALL
SELECT id, 'VIS-03' FROM parking_areas WHERE area_name = 'Visitor Parking' UNION ALL
SELECT id, 'VIS-04' FROM parking_areas WHERE area_name = 'Visitor Parking' UNION ALL
SELECT id, 'VIS-05' FROM parking_areas WHERE area_name = 'Visitor Parking';

-- Motorcycle Parking: 15 slots (MOT-01 through MOT-15)
INSERT INTO parking_slots (parking_area_id, slot_code)
SELECT id, 'MOT-01' FROM parking_areas WHERE area_name = 'Motorcycle Parking' UNION ALL
SELECT id, 'MOT-02' FROM parking_areas WHERE area_name = 'Motorcycle Parking' UNION ALL
SELECT id, 'MOT-03' FROM parking_areas WHERE area_name = 'Motorcycle Parking' UNION ALL
SELECT id, 'MOT-04' FROM parking_areas WHERE area_name = 'Motorcycle Parking' UNION ALL
SELECT id, 'MOT-05' FROM parking_areas WHERE area_name = 'Motorcycle Parking' UNION ALL
SELECT id, 'MOT-06' FROM parking_areas WHERE area_name = 'Motorcycle Parking' UNION ALL
SELECT id, 'MOT-07' FROM parking_areas WHERE area_name = 'Motorcycle Parking' UNION ALL
SELECT id, 'MOT-08' FROM parking_areas WHERE area_name = 'Motorcycle Parking' UNION ALL
SELECT id, 'MOT-09' FROM parking_areas WHERE area_name = 'Motorcycle Parking' UNION ALL
SELECT id, 'MOT-10' FROM parking_areas WHERE area_name = 'Motorcycle Parking' UNION ALL
SELECT id, 'MOT-11' FROM parking_areas WHERE area_name = 'Motorcycle Parking' UNION ALL
SELECT id, 'MOT-12' FROM parking_areas WHERE area_name = 'Motorcycle Parking' UNION ALL
SELECT id, 'MOT-13' FROM parking_areas WHERE area_name = 'Motorcycle Parking' UNION ALL
SELECT id, 'MOT-14' FROM parking_areas WHERE area_name = 'Motorcycle Parking' UNION ALL
SELECT id, 'MOT-15' FROM parking_areas WHERE area_name = 'Motorcycle Parking';

-- =========================================================
-- DEFAULT SUPER ADMIN
-- =========================================================
-- This gives a fresh installation an account that can access
-- the Admin panel. Change the password after first login.
--
-- Username: admin@campuspark.local
-- Password: admin123
-- =========================================================
INSERT INTO users
    (fullname, username, password, role, status, is_super_admin)
VALUES
    (
        'CampusPark Super Admin',
        'admin@campuspark.local',
        '$2y$12$MqiiAIG3oxlHaBNcXCOjQOqXUwhdzA9dyZK6gBI2YSk3c6Y2iHB.C',
        'admin',
        'active',
        1
    );

-- =========================================================
-- DATABASE RELATIONSHIP SUMMARY
-- =========================================================
-- users
--   └── vehicles
--         └── parking_permits
--                └── parking_slots
--                      └── parking_areas
--
-- vehicles / visitors
--   └── parking_logs
--
-- users / vehicles / visitors
--   └── violations
--
-- users
--   └── audit_logs
--
-- =========================================================
-- FRESH DATABASE READY
-- =========================================================
