<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261027221148 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Routine.isFavorite for the Heute "Schnellwechsel" chips.';
    }

    public function up(Schema $schema): void
    {
        // Cosmetic only - Doctrine's diff doesn't track column defaults via
        // attributes, so it re-surfaces this DEFAULT-clause removal on every
        // diff since User::$streakGraceDays was added; harmless, since the
        // entity's own PHP default (HistoryStatsBuilder::DEFAULT_GRACE_DAYS)
        // is what actually governs new rows, not the DB-level default.
        $this->addSql('ALTER TABLE app_user CHANGE streak_grace_days streak_grace_days INT NOT NULL');
        $this->addSql('ALTER TABLE routine ADD is_favorite TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE app_user CHANGE streak_grace_days streak_grace_days INT DEFAULT 3 NOT NULL');
        $this->addSql('ALTER TABLE routine DROP is_favorite');
    }
}
