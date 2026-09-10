<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260909211835 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the exercise/exercise_group/exercise_version/exercise_resource tables and extends practice_session into the unified, resumable session (status/exercise/resource/config_snapshot/learning_state/has_interaction). The status/has_interaction backfill below is a hand-added exception to the usual pure-diff migration convention, since existing rows need a real value, not just a column default.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE exercise (id INT AUTO_INCREMENT NOT NULL, visitor_id VARCHAR(36) NOT NULL, note LONGTEXT DEFAULT NULL, archived_at DATE DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_AEDAD51C70BEE6D (visitor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE exercise_group (id INT AUTO_INCREMENT NOT NULL, visitor_id VARCHAR(36) NOT NULL, name VARCHAR(40) NOT NULL, sort_index INT NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_48F6B30770BEE6D (visitor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE exercise_resource (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, sort_index INT NOT NULL, internal_tool_id VARCHAR(40) DEFAULT NULL, default_config_snapshot JSON DEFAULT NULL, external_url VARCHAR(2048) DEFAULT NULL, external_provider VARCHAR(60) DEFAULT NULL, external_title VARCHAR(200) DEFAULT NULL, created_at DATETIME NOT NULL, exercise_id INT NOT NULL, INDEX IDX_BAFD54FEE934951A (exercise_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE exercise_version (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(120) NOT NULL, target_minutes INT DEFAULT NULL, repeat_rhythm VARCHAR(20) NOT NULL, repeat_count INT DEFAULT NULL, valid_from DATE NOT NULL, valid_until DATE DEFAULT NULL, created_at DATETIME NOT NULL, exercise_id INT NOT NULL, group_id INT DEFAULT NULL, INDEX IDX_65092486E934951A (exercise_id), INDEX IDX_65092486E934951A7BD7DFB6FDEF8F78 (exercise_id, valid_from, valid_until), INDEX IDX_65092486FE54D947 (group_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE exercise_resource ADD CONSTRAINT FK_BAFD54FEE934951A FOREIGN KEY (exercise_id) REFERENCES exercise (id)');
        $this->addSql('ALTER TABLE exercise_version ADD CONSTRAINT FK_65092486E934951A FOREIGN KEY (exercise_id) REFERENCES exercise (id)');
        $this->addSql('ALTER TABLE exercise_version ADD CONSTRAINT FK_65092486FE54D947 FOREIGN KEY (group_id) REFERENCES exercise_group (id)');
        // status/has_interaction get a temporary DEFAULT so the ALTER succeeds against
        // existing rows, then an explicit backfill below sets the real value per row -
        // MySQL would otherwise reject a NOT NULL column added with no default on a
        // non-empty table.
        $this->addSql("ALTER TABLE practice_session ADD status VARCHAR(20) NOT NULL DEFAULT 'abgeschlossen', ADD config_snapshot JSON DEFAULT NULL, ADD learning_state VARCHAR(20) DEFAULT NULL, ADD has_interaction TINYINT NOT NULL DEFAULT 1, ADD exercise_id INT DEFAULT NULL, ADD resource_id INT DEFAULT NULL, CHANGE tool tool VARCHAR(40) DEFAULT NULL");
        $this->addSql('ALTER TABLE practice_session ADD CONSTRAINT FK_DE33C9D0E934951A FOREIGN KEY (exercise_id) REFERENCES exercise (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE practice_session ADD CONSTRAINT FK_DE33C9D089329D25 FOREIGN KEY (resource_id) REFERENCES exercise_resource (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_DE33C9D0E934951A ON practice_session (exercise_id)');
        $this->addSql('CREATE INDEX IDX_DE33C9D089329D25 ON practice_session (resource_id)');

        // Backfill: old rows predate the laufend/abgeschlossen/verworfen status
        // model. A round that was never marked finished has no browser tab left
        // to resume - treat it as an already-resolved abandon, not a resumable
        // "laufend" session (which would otherwise dangle forever, offering a
        // "resume" for a round nobody can actually continue).
        $this->addSql("UPDATE practice_session SET status = 'verworfen' WHERE finished_at IS NULL");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE exercise_resource DROP FOREIGN KEY FK_BAFD54FEE934951A');
        $this->addSql('ALTER TABLE exercise_version DROP FOREIGN KEY FK_65092486E934951A');
        $this->addSql('ALTER TABLE exercise_version DROP FOREIGN KEY FK_65092486FE54D947');
        $this->addSql('DROP TABLE exercise');
        $this->addSql('DROP TABLE exercise_group');
        $this->addSql('DROP TABLE exercise_resource');
        $this->addSql('DROP TABLE exercise_version');
        $this->addSql('ALTER TABLE practice_session DROP FOREIGN KEY FK_DE33C9D0E934951A');
        $this->addSql('ALTER TABLE practice_session DROP FOREIGN KEY FK_DE33C9D089329D25');
        $this->addSql('DROP INDEX IDX_DE33C9D0E934951A ON practice_session');
        $this->addSql('DROP INDEX IDX_DE33C9D089329D25 ON practice_session');
        $this->addSql('ALTER TABLE practice_session DROP status, DROP config_snapshot, DROP learning_state, DROP has_interaction, DROP exercise_id, DROP resource_id, CHANGE tool tool VARCHAR(40) NOT NULL');
    }
}
