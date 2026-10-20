<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020201500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the recalculation waiting to be confirmed, so the athlete can choose which values to keep';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Activity ADD pendingRecalculation CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Activity DROP pendingRecalculation');
    }
}
