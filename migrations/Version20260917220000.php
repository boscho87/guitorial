<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds day_note - one short free-text note per visitor per calendar day, shown on the Heute/history page.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE day_note (id INT AUTO_INCREMENT NOT NULL, visitor_id VARCHAR(36) NOT NULL, date DATE NOT NULL, note LONGTEXT NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_64D5176270BEE6DAA9E377A (visitor_id, date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE day_note');
    }
}
