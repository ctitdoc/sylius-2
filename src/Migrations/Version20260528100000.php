<?php

declare(strict_types=1);

namespace Alma\Sylius\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the legacy `alma_configuration` singleton table.
 *
 * The configuration is now stored in the `config` JSON of the unique Alma
 * PaymentMethod's gatewayConfig (Sylius-native pattern, see
 * specs-sylius/module-configuration). Greenfield MVP — no data preservation.
 */
final class Version20260528100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop legacy alma_configuration table (config moved into PaymentMethod gatewayConfig).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS alma_configuration');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE alma_configuration ('
            . 'id INT AUTO_INCREMENT NOT NULL, '
            . 'api_key_live VARCHAR(255) DEFAULT NULL, '
            . 'api_key_test VARCHAR(255) DEFAULT NULL, '
            . 'api_mode VARCHAR(8) NOT NULL, '
            . 'merchant_id VARCHAR(255) DEFAULT NULL, '
            . 'fee_plans JSON DEFAULT NULL, '
            . 'fee_plan_overrides JSON DEFAULT NULL, '
            . 'product_widget_enabled TINYINT(1) NOT NULL DEFAULT 0, '
            . 'PRIMARY KEY(id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
        );
    }
}
