<?php

declare(strict_types=1);

namespace Alma\Sylius\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the `product_widget_enabled` boolean toggle on the `alma_configuration`
 * singleton — single switch that drives whether the Alma product-page widget
 * is rendered (cf. capability `product-widget`).
 */
final class Version20260519160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product_widget_enabled BOOLEAN to alma_configuration (product-widget capability).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE alma_configuration ADD product_widget_enabled TINYINT(1) NOT NULL DEFAULT 0',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alma_configuration DROP product_widget_enabled');
    }
}
