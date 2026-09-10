<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260909211954 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drops the transitional DB-level defaults on practice_session.status/has_interaction now that the backfill in the previous migration has given every existing row an explicit value - keeps the schema in sync with the entity mapping, which declares no column default.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE
              practice_session
            CHANGE
              status status VARCHAR(20) NOT NULL,
            CHANGE
              has_interaction has_interaction TINYINT NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE
              practice_session
            CHANGE
              status status VARCHAR(20) DEFAULT 'abgeschlossen' NOT NULL,
            CHANGE
              has_interaction has_interaction TINYINT DEFAULT 1 NOT NULL
        SQL);
    }
}
