<?php

declare(strict_types=1);

namespace Alma\Sylius\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add the alma_payment_reference table — indexed pid → (sylius_payment, sylius_order, alma_mode)
 * lookup introduced after the post-Phase F audit (2026-05-19). Existing rows
 * in `sylius_payment.details` are backfilled so the new resolver works
 * immediately on data persisted before the migration.
 */
final class Version20260519100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add alma_payment_reference table + backfill from sylius_payment.details.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE alma_payment_reference (
                id INT AUTO_INCREMENT NOT NULL,
                sylius_payment_id INT NOT NULL,
                sylius_order_id INT NOT NULL,
                alma_payment_id VARCHAR(255) NOT NULL,
                alma_mode VARCHAR(8) NOT NULL,
                UNIQUE INDEX UNIQ_alma_payment_reference_alma_payment_id (alma_payment_id),
                UNIQUE INDEX UNIQ_alma_payment_reference_sylius_payment_id (sylius_payment_id),
                INDEX IDX_alma_payment_reference_sylius_order_id (sylius_order_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
        );
        $this->addSql(
            'ALTER TABLE alma_payment_reference
                ADD CONSTRAINT FK_alma_payment_reference_sylius_payment
                FOREIGN KEY (sylius_payment_id) REFERENCES sylius_payment (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE alma_payment_reference
                ADD CONSTRAINT FK_alma_payment_reference_sylius_order
                FOREIGN KEY (sylius_order_id) REFERENCES sylius_order (id) ON DELETE CASCADE',
        );

        // Backfill from existing sylius_payment.details JSON. MySQL 8.x.
        $this->addSql(
            "INSERT INTO alma_payment_reference (alma_payment_id, alma_mode, sylius_payment_id, sylius_order_id)
                SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(details, '$.alma_payment_id')),
                    JSON_UNQUOTE(JSON_EXTRACT(details, '$.alma_mode')),
                    id,
                    order_id
                FROM sylius_payment
                WHERE JSON_EXTRACT(details, '$.alma_payment_id') IS NOT NULL
                  AND JSON_EXTRACT(details, '$.alma_mode') IS NOT NULL",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE alma_payment_reference');
    }
}
