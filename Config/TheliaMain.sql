
# This is a fix for InnoDB in MySQL >= 4.1.x
# It "suspends judgement" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- social_login_identity
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `social_login_identity`;

CREATE TABLE `social_login_identity`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `customer_id` INTEGER NOT NULL,
    `provider` VARCHAR(32) NOT NULL,
    `provider_identifier` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255),
    `last_login_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `provider_provider_identifier_UNIQUE` (`provider`, `provider_identifier`),
    INDEX `idx_social_login_identity_customer_id` (`customer_id`),
    CONSTRAINT `fk_social_login_identity_customer`
        FOREIGN KEY (`customer_id`)
        REFERENCES `customer` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB;

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
