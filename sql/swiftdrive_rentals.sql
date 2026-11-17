-- PORTFOLIO DEMO ONLY: no original customer/booking/contact data is included.
-- =============================================================================
-- SwiftDrive Rentals (Negombo, Sri Lanka) - Schema and Synthetic Demo Seed Data
-- Compatible with MySQL 5.7+ / MySQL 8.0+ / MariaDB 10.4+ (XAMPP / MAMP)
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `swiftdrive_rentals`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `swiftdrive_rentals`;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `reviews`;
DROP TABLE IF EXISTS `inquiries`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `booking_inspections`;
DROP TABLE IF EXISTS `bookings`;
DROP TABLE IF EXISTS `vehicle_images`;
DROP TABLE IF EXISTS `vehicles`;
DROP TABLE IF EXISTS `locations`;
DROP TABLE IF EXISTS `vehicle_categories`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- 1. USERS TABLE
-- Roles: 'customer', 'staff', 'admin'
-- Soft deactivation via is_active preserves historical booking/audit records.
-- -----------------------------------------------------------------------------
CREATE TABLE `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `full_name` VARCHAR(120) NOT NULL,
    `email` VARCHAR(160) NOT NULL,
    `phone` VARCHAR(30) NOT NULL,
    `nic_or_passport` VARCHAR(40) DEFAULT NULL,
    `license_number` VARCHAR(50) DEFAULT NULL,
    `address` VARCHAR(255) DEFAULT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('customer', 'staff', 'admin') NOT NULL DEFAULT 'customer',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_role_active` (`role`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. VEHICLE CATEGORIES TABLE
-- Economy, SUV, Van, Luxury
-- -----------------------------------------------------------------------------
CREATE TABLE `vehicle_categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(80) NOT NULL,
    `slug` VARCHAR(80) NOT NULL,
    `description` TEXT NOT NULL,
    `excess_km_rate_lkr` DECIMAL(10,2) NOT NULL DEFAULT 60.00,
    `display_order` INT NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_categories_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. LOCATIONS TABLE
-- Pickup & Drop-off hubs in and around Negombo
-- -----------------------------------------------------------------------------
CREATE TABLE `locations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    `code` VARCHAR(20) NOT NULL,
    `address` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(30) NOT NULL,
    `opening_hours` VARCHAR(100) NOT NULL DEFAULT 'Daily 06:00 - 23:00',
    `surcharge_lkr` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_locations_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. VEHICLES TABLE
-- Stores fleet inventory, daily/weekly LKR rates, maintenance status, and active state.
-- -----------------------------------------------------------------------------
CREATE TABLE `vehicles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id` INT UNSIGNED NOT NULL,
    `base_location_id` INT UNSIGNED NOT NULL,
    `registration_number` VARCHAR(30) NOT NULL,
    `brand` VARCHAR(60) NOT NULL,
    `model` VARCHAR(80) NOT NULL,
    `year` SMALLINT UNSIGNED NOT NULL,
    `transmission` ENUM('Automatic', 'Manual') NOT NULL DEFAULT 'Automatic',
    `fuel_type` ENUM('Petrol', 'Diesel', 'Hybrid', 'Electric') NOT NULL DEFAULT 'Petrol',
    `seats` TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `luggage_capacity` TINYINT UNSIGNED NOT NULL DEFAULT 2,
    `air_conditioning` TINYINT(1) NOT NULL DEFAULT 1,
    `daily_rate_lkr` DECIMAL(12,2) NOT NULL,
    `weekly_rate_lkr` DECIMAL(12,2) NOT NULL,
    `security_deposit_lkr` DECIMAL(12,2) NOT NULL DEFAULT 25000.00,
    `mileage_km` INT UNSIGNED NOT NULL DEFAULT 0,
    `maintenance_status` ENUM('available', 'maintenance', 'retired') NOT NULL DEFAULT 'available',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
    `description` TEXT NOT NULL,
    `features_list` VARCHAR(500) NOT NULL DEFAULT 'Air Conditioning, Bluetooth Audio, ABS, Reverse Camera',
    `primary_image` VARCHAR(255) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_vehicles_reg` (`registration_number`),
    KEY `idx_vehicles_search` (`category_id`, `is_active`, `maintenance_status`, `daily_rate_lkr`),
    KEY `idx_vehicles_location` (`base_location_id`),
    CONSTRAINT `fk_vehicles_category` FOREIGN KEY (`category_id`) REFERENCES `vehicle_categories` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_vehicles_location` FOREIGN KEY (`base_location_id`) REFERENCES `locations` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. VEHICLE IMAGES TABLE
-- Supports gallery images per vehicle
-- -----------------------------------------------------------------------------
CREATE TABLE `vehicle_images` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `vehicle_id` INT UNSIGNED NOT NULL,
    `image_path` VARCHAR(255) NOT NULL,
    `caption` VARCHAR(120) DEFAULT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_vehicle_images_vehicle` (`vehicle_id`),
    CONSTRAINT `fk_vehicle_images_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. BOOKINGS TABLE
-- Interval convention: [pickup_datetime, return_datetime) half-open interval.
-- Two bookings overlap iff existing.pickup_datetime < new.return_datetime
--                       AND existing.return_datetime > new.pickup_datetime.
-- Statuses that reserve vehicle availability: 'pending', 'confirmed', 'picked_up'
-- Statuses that release vehicle availability: 'completed', 'cancelled', 'rejected'
-- -----------------------------------------------------------------------------
CREATE TABLE `bookings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `booking_reference` VARCHAR(32) NOT NULL,
    `customer_id` INT UNSIGNED NOT NULL,
    `vehicle_id` INT UNSIGNED NOT NULL,
    `pickup_location_id` INT UNSIGNED NOT NULL,
    `return_location_id` INT UNSIGNED NOT NULL,
    `pickup_datetime` DATETIME NOT NULL,
    `return_datetime` DATETIME NOT NULL,
    `total_days` INT UNSIGNED NOT NULL,
    `full_weeks` INT UNSIGNED NOT NULL DEFAULT 0,
    `remaining_days` INT UNSIGNED NOT NULL DEFAULT 0,
    `daily_rate_snapshot_lkr` DECIMAL(12,2) NOT NULL,
    `weekly_rate_snapshot_lkr` DECIMAL(12,2) NOT NULL,
    `base_rental_lkr` DECIMAL(12,2) NOT NULL,
    `location_surcharge_lkr` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `deposit_required_lkr` DECIMAL(12,2) NOT NULL,
    `late_penalty_lkr` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `extra_charges_lkr` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_amount_lkr` DECIMAL(12,2) NOT NULL,
    `amount_paid_lkr` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `balance_due_lkr` DECIMAL(12,2) NOT NULL,
    `payment_status` ENUM('unpaid', 'deposit_paid', 'fully_paid', 'failed', 'refunded') NOT NULL DEFAULT 'unpaid',
    `booking_status` ENUM('pending', 'confirmed', 'picked_up', 'completed', 'cancelled', 'rejected') NOT NULL DEFAULT 'pending',
    `customer_notes` TEXT DEFAULT NULL,
    `staff_notes` TEXT DEFAULT NULL,
    `cancellation_reason` VARCHAR(255) DEFAULT NULL,
    `cancelled_at` DATETIME DEFAULT NULL,
    `confirmed_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bookings_reference` (`booking_reference`),
    KEY `idx_bookings_vehicle_dates` (`vehicle_id`, `booking_status`, `pickup_datetime`, `return_datetime`),
    KEY `idx_bookings_customer` (`customer_id`, `created_at`),
    KEY `idx_bookings_status` (`booking_status`),
    CONSTRAINT `fk_bookings_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_bookings_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_bookings_pickup_loc` FOREIGN KEY (`pickup_location_id`) REFERENCES `locations` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_bookings_return_loc` FOREIGN KEY (`return_location_id`) REFERENCES `locations` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_bookings_confirmed_by` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. BOOKING INSPECTIONS TABLE (Pickup & Return Dispatch Records)
-- Tracks odometer mileage, fuel level (eighths: 1/8 to 8/8), condition notes,
-- late return hours, excess mileage charges, and fuel refill charges.
-- -----------------------------------------------------------------------------
CREATE TABLE `booking_inspections` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `booking_id` INT UNSIGNED NOT NULL,
    `pickup_recorded_by` INT UNSIGNED DEFAULT NULL,
    `pickup_actual_at` DATETIME DEFAULT NULL,
    `pickup_mileage_km` INT UNSIGNED DEFAULT NULL,
    `pickup_fuel_eighths` TINYINT UNSIGNED DEFAULT NULL COMMENT '1 to 8 representing 1/8 to 8/8 (Full)',
    `pickup_condition_notes` TEXT DEFAULT NULL,
    `return_recorded_by` INT UNSIGNED DEFAULT NULL,
    `return_actual_at` DATETIME DEFAULT NULL,
    `return_mileage_km` INT UNSIGNED DEFAULT NULL,
    `return_fuel_eighths` TINYINT UNSIGNED DEFAULT NULL COMMENT '1 to 8 representing 1/8 to 8/8 (Full)',
    `return_condition_notes` TEXT DEFAULT NULL,
    `included_km_allowance` INT UNSIGNED DEFAULT 0,
    `excess_km` INT UNSIGNED DEFAULT 0,
    `excess_km_charge_lkr` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `fuel_deficit_eighths` TINYINT UNSIGNED DEFAULT 0,
    `fuel_charge_lkr` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `late_hours` INT UNSIGNED DEFAULT 0,
    `late_penalty_lkr` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `damage_fee_lkr` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_inspections_booking` (`booking_id`),
    CONSTRAINT `fk_inspections_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_inspections_pickup_staff` FOREIGN KEY (`pickup_recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_inspections_return_staff` FOREIGN KEY (`return_recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8. PAYMENTS TABLE
-- Online payment gateway and counter payment records
-- -----------------------------------------------------------------------------
CREATE TABLE `payments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `payment_reference` VARCHAR(40) NOT NULL,
    `booking_id` INT UNSIGNED NOT NULL,
    `customer_id` INT UNSIGNED NOT NULL,
    `payment_type` ENUM('deposit', 'full_payment', 'balance_settlement') NOT NULL DEFAULT 'deposit',
    `payment_method` ENUM('Online Card Gateway', 'Bank Transfer Reference', 'Cash at Negombo Counter') NOT NULL DEFAULT 'Online Card Gateway',
    `amount_lkr` DECIMAL(12,2) NOT NULL,
    `payment_status` ENUM('succeeded', 'failed') NOT NULL,
    `simulation_scenario` VARCHAR(80) NOT NULL DEFAULT 'Online Payment Authorization (Approved)',
    `notes` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_payments_reference` (`payment_reference`),
    KEY `idx_payments_booking` (`booking_id`, `payment_status`),
    KEY `idx_payments_customer` (`customer_id`),
    CONSTRAINT `fk_payments_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_payments_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 9. INQUIRIES TABLE
-- Public & customer inquiries with staff review workflow
-- -----------------------------------------------------------------------------
CREATE TABLE `inquiries` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `full_name` VARCHAR(120) NOT NULL,
    `email` VARCHAR(160) NOT NULL,
    `phone` VARCHAR(30) NOT NULL,
    `subject` VARCHAR(160) NOT NULL,
    `message` TEXT NOT NULL,
    `status` ENUM('new', 'in_progress', 'resolved') NOT NULL DEFAULT 'new',
    `staff_reply` TEXT DEFAULT NULL,
    `handled_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_inquiries_status` (`status`, `created_at`),
    CONSTRAINT `fk_inquiries_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_inquiries_staff` FOREIGN KEY (`handled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 10. REVIEWS TABLE
-- -----------------------------------------------------------------------------
CREATE TABLE `reviews` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reviewer_name` VARCHAR(100) NOT NULL,
    `reviewer_origin` VARCHAR(100) NOT NULL,
    `vehicle_label` VARCHAR(100) NOT NULL,
    `rating` TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `comment` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SEED DATA
-- =============================================================================

-- 1. Seed Users
-- Default Accounts:
-- Admin:    admin@swiftdrive.lk    / Admin@123
-- Staff:    staff@swiftdrive.lk    / Staff@123
-- Staff 2:  roshan@swiftdrive.lk   / Staff@123
-- Original customer fixture omitted for privacy.
-- Original customer fixture omitted for privacy.
-- 2. Seed Vehicle Categories
-- 3. Seed Locations (Negombo & Airport Corridor)
-- 4. Seed Vehicles (10 Realistic Sri Lankan Rental Fleet Vehicles across all 4 categories)
-- 5. Seed Vehicle Images
-- 6. Seed Bookings across different lifecycle states
-- 7. Seed Booking Inspections (for Booking 1 completed and Booking 2 picked_up)
-- 8. Seed Payments
-- 9. Seed Inquiries
-- 10. Seed Customer Reviews


-- PORTFOLIO SYNTHETIC DEMO FIXTURES. None of the original user, booking,
-- inspection, inquiry, payment, or review records have been published.
-- These are fictional accounts and prices; never enable on an online service.
INSERT INTO users (id, full_name, email, phone, nic_or_passport, license_number, address, password_hash, role, is_active) VALUES
(1, 'Demo Administrator', 'admin@example.test', '0000000000', NULL, NULL, 'Demo location only', '$2b$12$jcozkshMVhRR/dCKQ1fnHOwxOGahGRbVwUgS79ILIFB2jp7dYIuZ2', 'admin', 1),
(2, 'Demo Staff', 'staff@example.test', '0000000000', NULL, NULL, 'Demo location only', '$2b$12$H1sZ.bCYrMNk/3HKYcxRRuJBX/VqwImFkHtuXFi.OBinHNi4W4dI6', 'staff', 1),
(3, 'Demo Customer', 'customer@example.test', '0000000000', NULL, NULL, 'Demo location only', '$2b$12$Q.GxL65cUi6eB.sus1g.Ue1i1kOlGs5bSq9x1KERXvPhaW7oWPR3K', 'customer', 1);

INSERT INTO vehicle_categories (id, name, slug, description, excess_km_rate_lkr, display_order) VALUES
(1, 'Economy', 'economy', 'Synthetic economy vehicle catalog', 50, 1),
(2, 'SUV', 'suv', 'Synthetic SUV vehicle catalog', 75, 2),
(3, 'Van', 'van', 'Synthetic van vehicle catalog', 90, 3),
(4, 'Luxury', 'luxury', 'Synthetic luxury vehicle catalog', 140, 4);

INSERT INTO locations (id, name, code, address, phone, opening_hours, surcharge_lkr, is_active) VALUES
(1, 'Demo Negombo Pickup', 'NEG-DEMO', 'Fictional demonstration location', '0000000000', 'Daily 06:00 - 23:00', 0, 1),
(2, 'Demo Colombo Pickup', 'COL-DEMO', 'Fictional demonstration location', '0000000000', 'Daily 06:00 - 23:00', 2500, 1);

INSERT INTO vehicles (id, category_id, base_location_id, registration_number, brand, model, year, daily_rate_lkr, weekly_rate_lkr, description, primary_image) VALUES
(1, 1, 1, 'DEMO-001', 'Suzuki', 'Alto', 2022, 5500, 33000, 'Fictional local-demo vehicle; not a live rental offer.', 'assets/images/vehicles/suzuki-alto.jpg'),
(2, 2, 1, 'DEMO-002', 'Toyota', 'Raize', 2023, 8500, 52000, 'Fictional local-demo vehicle; not a live rental offer.', 'assets/images/vehicles/toyota-raize.jpg'),
(3, 2, 2, 'DEMO-003', 'Honda', 'Vezel', 2022, 11500, 70000, 'Fictional local-demo vehicle; not a live rental offer.', 'assets/images/vehicles/honda-vezel.jpg'),
(4, 4, 2, 'DEMO-004', 'BMW', '520i', 2022, 28000, 160000, 'Fictional local-demo vehicle; not a live rental offer.', 'assets/images/vehicles/bmw-520i.jpg');

INSERT INTO vehicle_images (vehicle_id, image_path, caption, is_primary) VALUES
(1, 'assets/images/vehicles/suzuki-alto.jpg', 'Suzuki Alto demo', 1),
(2, 'assets/images/vehicles/toyota-raize.jpg', 'Toyota Raize demo', 1),
(3, 'assets/images/vehicles/honda-vezel.jpg', 'Honda Vezel demo', 1),
(4, 'assets/images/vehicles/bmw-520i.jpg', 'BMW 520i demo', 1);
