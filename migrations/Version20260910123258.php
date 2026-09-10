<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260910123258 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the app_user table for optional OAuth login (Google/Microsoft) - purely additive, no existing table changes.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, visitor_id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_88BDF3E9E7927C74 (email), UNIQUE INDEX UNIQ_88BDF3E970BEE6D (visitor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE app_user');
    }
}
