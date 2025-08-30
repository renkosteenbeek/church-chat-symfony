<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250830_RemoveMetadataAddSpecificFields extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove metadata JSON field and add specific typed columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE members ADD last_attendance_date DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE members ADD notifications_paused_until DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE members ADD notification_frequency VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE members ADD unsubscribe_reason TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE members ADD unsubscribe_date DATE DEFAULT NULL');
        
        $this->addSql('ALTER TABLE members DROP metadata');
        
        $this->addSql('ALTER TABLE chat_history DROP metadata');
        
        $this->addSql('ALTER TABLE content_status DROP metadata');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE members ADD metadata JSON DEFAULT NULL');
        
        $this->addSql('ALTER TABLE members DROP last_attendance_date');
        $this->addSql('ALTER TABLE members DROP notifications_paused_until');
        $this->addSql('ALTER TABLE members DROP notification_frequency');
        $this->addSql('ALTER TABLE members DROP unsubscribe_reason');
        $this->addSql('ALTER TABLE members DROP unsubscribe_date');
        
        $this->addSql('ALTER TABLE chat_history ADD metadata JSON DEFAULT NULL');
        
        $this->addSql('ALTER TABLE content_status ADD metadata JSON DEFAULT NULL');
    }
}