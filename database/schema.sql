-- ==============================================================================
-- IDEAPAD Seed Fund Portal: Pure MySQL 8 / phpMyAdmin Database Schema
-- Character Set: utf8mb4, Collation: utf8mb4_unicode_ci
-- Compatible with MySQL 8.0+, 8.4 LTS, and phpMyAdmin import
-- ==============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Users table (Startup founders, SEC members, Administrators)
CREATE TABLE IF NOT EXISTS `User` (
    `id` VARCHAR(191) NOT NULL,
    `email` VARCHAR(191) NOT NULL,
    `name` VARCHAR(191) NOT NULL,
    `passwordHash` VARCHAR(191) NOT NULL,
    `role` ENUM('STARTUP', 'SEC_MEMBER', 'ADMIN') NOT NULL,
    `approvalStatus` ENUM('PENDING', 'APPROVED', 'REJECTED') NOT NULL DEFAULT 'APPROVED',
    `mustChangePassword` BOOLEAN NOT NULL DEFAULT false,
    `active` BOOLEAN NOT NULL DEFAULT true,
    `designation` VARCHAR(191) NULL,
    `department` VARCHAR(191) NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE INDEX `User_email_key`(`email`),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 2. Startup team profile
CREATE TABLE IF NOT EXISTS `Startup` (
    `id` VARCHAR(191) NOT NULL,
    `userId` VARCHAR(191) NOT NULL,
    `teamName` VARCHAR(191) NOT NULL,
    `enrollmentNo` VARCHAR(191) NOT NULL,
    `program` VARCHAR(191) NOT NULL,
    `phone` VARCHAR(191) NOT NULL,
    UNIQUE INDEX `Startup_userId_key`(`userId`),
    PRIMARY KEY (`id`),
    CONSTRAINT `Startup_userId_fkey` FOREIGN KEY (`userId`) REFERENCES `User`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3. Applications
CREATE TABLE IF NOT EXISTS `Application` (
    `id` VARCHAR(191) NOT NULL,
    `startupId` VARCHAR(191) NOT NULL,
    `version` INT NOT NULL DEFAULT 1,
    `supersedesId` VARCHAR(191) NULL,
    `status` ENUM('DRAFT', 'SUBMITTED', 'IN_REVIEW', 'APPROVED', 'REJECTED', 'REPRESENT', 'SUPERSEDED') NOT NULL DEFAULT 'DRAFT',
    `grantType` ENUM('IDEATION', 'VALIDATION', 'PROOF', 'SCALING', 'EMERGENCY') NULL,
    `requestedTotal` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `formData` JSON NOT NULL,
    `submittedAt` DATETIME(3) NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE INDEX `Application_supersedesId_key`(`supersedesId`),
    INDEX `Application_startupId_idx`(`startupId`),
    INDEX `Application_status_idx`(`status`),
    PRIMARY KEY (`id`),
    CONSTRAINT `Application_startupId_fkey` FOREIGN KEY (`startupId`) REFERENCES `Startup`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 4. Documents attached to applications
CREATE TABLE IF NOT EXISTS `Document` (
    `id` VARCHAR(191) NOT NULL,
    `applicationId` VARCHAR(191) NOT NULL,
    `type` ENUM('AGREEMENT', 'PAN', 'AADHAAR', 'PHOTO', 'DECK', 'QUOTATION', 'UTILIZATION_REPORT', 'ANNEXURE') NOT NULL,
    `memberLabel` VARCHAR(191) NULL,
    `path` TEXT NOT NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    INDEX `Document_applicationId_idx`(`applicationId`),
    PRIMARY KEY (`id`),
    CONSTRAINT `Document_applicationId_fkey` FOREIGN KEY (`applicationId`) REFERENCES `Application`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 5. Budget items
CREATE TABLE IF NOT EXISTS `BudgetItem` (
    `id` VARCHAR(191) NOT NULL,
    `applicationId` VARCHAR(191) NOT NULL,
    `header` VARCHAR(191) NOT NULL,
    `name` VARCHAR(191) NOT NULL,
    `vendor` VARCHAR(191) NULL,
    `unitCost` DECIMAL(12, 2) NOT NULL,
    `qty` INT NOT NULL,
    `total` DECIMAL(12, 2) NOT NULL,
    `justification` TEXT NULL,
    INDEX `BudgetItem_applicationId_idx`(`applicationId`),
    PRIMARY KEY (`id`),
    CONSTRAINT `BudgetItem_applicationId_fkey` FOREIGN KEY (`applicationId`) REFERENCES `Application`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 6. Individual SEC member assessments (Form A)
CREATE TABLE IF NOT EXISTS `Assessment` (
    `id` VARCHAR(191) NOT NULL,
    `applicationId` VARCHAR(191) NOT NULL,
    `memberId` VARCHAR(191) NOT NULL,
    `assessmentDate` DATETIME(3) NULL,
    `preChecks` JSON NOT NULL,
    `statusForm` JSON NOT NULL,
    `experimentForm` JSON NOT NULL,
    `recommendation` TEXT NULL,
    `suggestedGrant` VARCHAR(191) NULL,
    `suggestedAmounts` JSON NOT NULL,
    `outdated` BOOLEAN NOT NULL DEFAULT false,
    `status` ENUM('DRAFT', 'SUBMITTED') NOT NULL DEFAULT 'DRAFT',
    `submittedAt` DATETIME(3) NULL,
    UNIQUE INDEX `Assessment_applicationId_memberId_key`(`applicationId`, `memberId`),
    PRIMARY KEY (`id`),
    CONSTRAINT `Assessment_applicationId_fkey` FOREIGN KEY (`applicationId`) REFERENCES `Application`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `Assessment_memberId_fkey` FOREIGN KEY (`memberId`) REFERENCES `User`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 7. Shared Milestone Assessment container
CREATE TABLE IF NOT EXISTS `MilestoneAssessment` (
    `id` VARCHAR(191) NOT NULL,
    `applicationId` VARCHAR(191) NOT NULL,
    `ownerId` VARCHAR(191) NOT NULL,
    `track` VARCHAR(191) NOT NULL,
    `status` ENUM('DRAFT', 'SUBMITTED') NOT NULL DEFAULT 'DRAFT',
    `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE INDEX `MilestoneAssessment_applicationId_key`(`applicationId`),
    PRIMARY KEY (`id`),
    CONSTRAINT `MilestoneAssessment_applicationId_fkey` FOREIGN KEY (`applicationId`) REFERENCES `Application`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `MilestoneAssessment_ownerId_fkey` FOREIGN KEY (`ownerId`) REFERENCES `User`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 8. Milestone Items
CREATE TABLE IF NOT EXISTS `MilestoneItem` (
    `id` VARCHAR(191) NOT NULL,
    `milestoneId` VARCHAR(191) NOT NULL,
    `name` VARCHAR(191) NOT NULL,
    `state` ENUM('COMPLETED', 'PENDING', 'IN_PROGRESS') NOT NULL DEFAULT 'PENDING',
    `ownerRemark` TEXT NULL,
    `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    CONSTRAINT `MilestoneItem_milestoneId_fkey` FOREIGN KEY (`milestoneId`) REFERENCES `MilestoneAssessment`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 9. Committee remarks on milestone items
CREATE TABLE IF NOT EXISTS `MilestoneRemark` (
    `id` VARCHAR(191) NOT NULL,
    `itemId` VARCHAR(191) NOT NULL,
    `memberId` VARCHAR(191) NOT NULL,
    `remark` TEXT NOT NULL,
    `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE INDEX `MilestoneRemark_itemId_memberId_key`(`itemId`, `memberId`),
    PRIMARY KEY (`id`),
    CONSTRAINT `MilestoneRemark_itemId_fkey` FOREIGN KEY (`itemId`) REFERENCES `MilestoneItem`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `MilestoneRemark_memberId_fkey` FOREIGN KEY (`memberId`) REFERENCES `User`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 10. Milestone edit audit log
CREATE TABLE IF NOT EXISTS `MilestoneEditLog` (
    `id` VARCHAR(191) NOT NULL,
    `milestoneId` VARCHAR(191) NOT NULL,
    `memberId` VARCHAR(191) NOT NULL,
    `field` VARCHAR(191) NOT NULL,
    `oldValue` TEXT NULL,
    `newValue` TEXT NULL,
    `at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    CONSTRAINT `MilestoneEditLog_milestoneId_fkey` FOREIGN KEY (`milestoneId`) REFERENCES `MilestoneAssessment`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 11. Form B Grant Evaluation & Sanctions (by Admin)
CREATE TABLE IF NOT EXISTS `GrantEvaluation` (
    `id` VARCHAR(191) NOT NULL,
    `applicationId` VARCHAR(191) NOT NULL,
    `adminId` VARCHAR(191) NOT NULL,
    `status` ENUM('DRAFT', 'SUBMITTED') NOT NULL DEFAULT 'DRAFT',
    `assessmentDate` DATETIME(3) NULL,
    `decision` VARCHAR(191) NOT NULL,
    `approvedTotal` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `amounts` JSON NOT NULL,
    `timeline` JSON NOT NULL,
    `note` TEXT NULL,
    `secDecisionNote` TEXT NULL,
    `termsAcknowledged` BOOLEAN NOT NULL DEFAULT false,
    `signedCopyPath` TEXT NULL,
    `forced` BOOLEAN NOT NULL DEFAULT false,
    `forceReason` TEXT NULL,
    `decidedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE INDEX `GrantEvaluation_applicationId_key`(`applicationId`),
    PRIMARY KEY (`id`),
    CONSTRAINT `GrantEvaluation_applicationId_fkey` FOREIGN KEY (`applicationId`) REFERENCES `Application`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `GrantEvaluation_adminId_fkey` FOREIGN KEY (`adminId`) REFERENCES `User`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 12. Audit Logs
CREATE TABLE IF NOT EXISTS `AuditLog` (
    `id` VARCHAR(191) NOT NULL,
    `userId` VARCHAR(191) NULL,
    `action` VARCHAR(191) NOT NULL,
    `targetType` VARCHAR(191) NOT NULL,
    `targetId` VARCHAR(191) NULL,
    `details` JSON NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
