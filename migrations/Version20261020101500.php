<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020101500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the recalculated activity metrics, so the values reported by the device are kept and can always be restored';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Activity ADD recalculatedMetrics CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Activity DROP recalculatedMetrics');
    }
}
