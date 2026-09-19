<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260919151445 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds quiz_attempt.visitor_id, so the weighted-question picker weights each visitor\'s own wrong answers instead of a global pool shared by everyone. Existing rows backfill to \'\' (unknown visitor).';
    }

    public function up(Schema $schema): void
    {
        // Nullable first, then backfilled and tightened - a plain NOT NULL
        // ADD would fail against existing rows, and a NOT NULL DEFAULT ''
        // would leave a stray default the entity mapping doesn't declare,
        // which doctrine:schema:validate would then flag as out of sync.
        $this->addSql('ALTER TABLE quiz_attempt ADD visitor_id VARCHAR(36) DEFAULT NULL');
        $this->addSql("UPDATE quiz_attempt SET visitor_id = '' WHERE visitor_id IS NULL");
        $this->addSql('ALTER TABLE quiz_attempt CHANGE visitor_id visitor_id VARCHAR(36) NOT NULL');
        $this->addSql('CREATE INDEX IDX_AB6AFC620F33ED170BEE6D ON quiz_attempt (tool, visitor_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_AB6AFC620F33ED170BEE6D ON quiz_attempt');
        $this->addSql('ALTER TABLE quiz_attempt DROP visitor_id');
    }
}
