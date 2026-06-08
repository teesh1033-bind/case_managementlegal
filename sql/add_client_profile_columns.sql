-- Client type and corporate profile fields
ALTER TABLE `clients`
  ADD COLUMN IF NOT EXISTS `client_type` VARCHAR(20) NOT NULL DEFAULT 'Individual' AFTER `phone`,
  ADD COLUMN IF NOT EXISTS `address` TEXT NULL AFTER `client_type`,
  ADD COLUMN IF NOT EXISTS `business_name` VARCHAR(255) NULL AFTER `address`,
  ADD COLUMN IF NOT EXISTS `business_address` TEXT NULL AFTER `business_name`,
  ADD COLUMN IF NOT EXISTS `brn` VARCHAR(100) NULL AFTER `business_address`,
  ADD COLUMN IF NOT EXISTS `business_description` TEXT NULL AFTER `brn`;
