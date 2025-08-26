<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250126000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Member, ContentStatus and ChatHistory tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE members (
            id CHAR(36) NOT NULL,
            openai_conversation_id VARCHAR(255) DEFAULT NULL,
            first_name VARCHAR(100) DEFAULT NULL,
            target_group VARCHAR(20) DEFAULT NULL,
            age INT DEFAULT NULL,
            intake_completed TINYINT(1) NOT NULL DEFAULT 0,
            notifications_new_service TINYINT(1) NOT NULL DEFAULT 1,
            notifications_reflection TINYINT(1) NOT NULL DEFAULT 1,
            phone_number VARCHAR(20) NOT NULL,
            platform VARCHAR(20) NOT NULL DEFAULT \'signal\',
            active_since DATETIME NOT NULL,
            active_sermon_id VARCHAR(255) DEFAULT NULL,
            church_ids JSON NOT NULL,
            last_activity DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            UNIQUE INDEX UNIQ_45A07467444F97DD (phone_number),
            INDEX idx_phone_number (phone_number),
            INDEX idx_last_activity (last_activity)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE content_status (
            id CHAR(36) NOT NULL,
            member_id CHAR(36) NOT NULL,
            content_id VARCHAR(255) NOT NULL,
            church_id INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'queued\',
            schedule_date DATETIME DEFAULT NULL,
            sent_date DATETIME DEFAULT NULL,
            error_message LONGTEXT DEFAULT NULL,
            retry_count INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX IDX_B5D551497597D3FE (member_id),
            INDEX idx_status (status),
            INDEX idx_church_id (church_id),
            INDEX idx_schedule_date (schedule_date),
            INDEX idx_member_status (member_id, status),
            CONSTRAINT FK_B5D551497597D3FE FOREIGN KEY (member_id) REFERENCES members (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE chat_history (
            id CHAR(36) NOT NULL,
            member_id CHAR(36) NOT NULL,
            conversation_id VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL,
            content LONGTEXT NOT NULL,
            openai_response_id VARCHAR(255) DEFAULT NULL,
            tool_calls JSON DEFAULT NULL,
            metadata JSON DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX IDX_BE60CBEC7597D3FE (member_id),
            INDEX idx_member_id (member_id),
            INDEX idx_conversation_id (conversation_id),
            INDEX idx_created_at (created_at),
            CONSTRAINT FK_BE60CBEC7597D3FE FOREIGN KEY (member_id) REFERENCES members (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE chat_history');
        $this->addSql('DROP TABLE content_status');
        $this->addSql('DROP TABLE members');
    }
}