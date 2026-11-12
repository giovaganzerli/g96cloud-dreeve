<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add collections, a set of activities that share an experience, and the surface analysis of an activity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE Collection (collectionId VARCHAR(255) NOT NULL, createdOn DATETIME NOT NULL, name VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, localImagePath VARCHAR(255) DEFAULT NULL, activityIds CLOB NOT NULL, immichAlbumId VARCHAR(255) DEFAULT NULL, statisticOverrides CLOB NOT NULL, customStatistics CLOB NOT NULL, statisticOrder CLOB NOT NULL, PRIMARY KEY(collectionId))');
        $this->addSql('CREATE INDEX Collection_createdOnIndex ON Collection (createdOn)');
        $this->addSql('CREATE TABLE ActivitySurface (activityId VARCHAR(255) NOT NULL, pavedDistanceInMeter DOUBLE PRECISION NOT NULL, unpavedDistanceInMeter DOUBLE PRECISION NOT NULL, unknownDistanceInMeter DOUBLE PRECISION NOT NULL, analyzedOn DATETIME NOT NULL, PRIMARY KEY(activityId))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ActivitySurface');
        $this->addSql('DROP TABLE Collection');
    }
}
