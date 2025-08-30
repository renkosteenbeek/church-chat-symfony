<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250828_AddMetadataFields extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add metadata fields to members and content_status tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE members ADD metadata JSON DEFAULT NULL');
        
        $this->addSql('ALTER TABLE content_status ADD metadata JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE members DROP metadata');
        
        $this->addSql('ALTER TABLE content_status DROP metadata');
    }
}