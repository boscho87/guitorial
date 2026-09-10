<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260909213910 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds ON DELETE CASCADE (exercise_id) and ON DELETE SET NULL (group_id) to exercise_version/exercise_resource, fixing a Phase 0 gap: hard-deleting an Exercise or an ExerciseGroup would otherwise fail with a foreign key constraint violation.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE exercise_resource DROP FOREIGN KEY `FK_BAFD54FEE934951A`');
        $this->addSql('ALTER TABLE exercise_resource ADD CONSTRAINT FK_BAFD54FEE934951A FOREIGN KEY (exercise_id) REFERENCES exercise (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE exercise_version DROP FOREIGN KEY `FK_65092486E934951A`');
        $this->addSql('ALTER TABLE exercise_version DROP FOREIGN KEY `FK_65092486FE54D947`');
        $this->addSql('ALTER TABLE exercise_version ADD CONSTRAINT FK_65092486E934951A FOREIGN KEY (exercise_id) REFERENCES exercise (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE exercise_version ADD CONSTRAINT FK_65092486FE54D947 FOREIGN KEY (group_id) REFERENCES exercise_group (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE exercise_resource DROP FOREIGN KEY FK_BAFD54FEE934951A');
        $this->addSql('ALTER TABLE exercise_resource ADD CONSTRAINT `FK_BAFD54FEE934951A` FOREIGN KEY (exercise_id) REFERENCES exercise (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE exercise_version DROP FOREIGN KEY FK_65092486E934951A');
        $this->addSql('ALTER TABLE exercise_version DROP FOREIGN KEY FK_65092486FE54D947');
        $this->addSql('ALTER TABLE exercise_version ADD CONSTRAINT `FK_65092486E934951A` FOREIGN KEY (exercise_id) REFERENCES exercise (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE exercise_version ADD CONSTRAINT `FK_65092486FE54D947` FOREIGN KEY (group_id) REFERENCES exercise_group (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
    }
}
