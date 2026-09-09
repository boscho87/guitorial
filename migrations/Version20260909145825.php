<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260909145825 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE diary_check (id INT AUTO_INCREMENT NOT NULL, checked_date DATE NOT NULL, created_at DATETIME NOT NULL, diary_item_id INT NOT NULL, UNIQUE INDEX UNIQ_7D910C324826A54A6ADD5F20 (diary_item_id, checked_date), INDEX IDX_7D910C324826A54A (diary_item_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE diary_item (id INT AUTO_INCREMENT NOT NULL, visitor_id VARCHAR(36) NOT NULL, label VARCHAR(120) NOT NULL, category VARCHAR(20) NOT NULL, linked_tool VARCHAR(40) DEFAULT NULL, target_minutes INT DEFAULT NULL, valid_from DATE NOT NULL, valid_until DATE DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_4C164B570BEE6D (visitor_id), INDEX IDX_4C164B570BEE6D7BD7DFB6FDEF8F78 (visitor_id, valid_from, valid_until), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE diary_check ADD CONSTRAINT FK_7D910C324826A54A FOREIGN KEY (diary_item_id) REFERENCES diary_item (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE diary_check DROP FOREIGN KEY FK_7D910C324826A54A');
        $this->addSql('DROP TABLE diary_check');
        $this->addSql('DROP TABLE diary_item');
    }
}
