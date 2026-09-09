<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260909024856 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE practice_session (id INT AUTO_INCREMENT NOT NULL, visitor_id VARCHAR(36) NOT NULL, client_session_id VARCHAR(36) NOT NULL, tool VARCHAR(40) NOT NULL, question_index INT NOT NULL, total_questions INT DEFAULT NULL, correct_count INT DEFAULT NULL, started_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, INDEX IDX_DE33C9D070BEE6D (visitor_id), UNIQUE INDEX UNIQ_DE33C9D070BEE6DF16BD19E (visitor_id, client_session_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE practice_session');
    }
}
