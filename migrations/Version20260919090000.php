<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260919090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds exercise.imported_from_exercise_id and routine.imported_from_routine_id, so re-importing a shared routine reuses previously-imported copies instead of cloning duplicates.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exercise ADD imported_from_exercise_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE exercise ADD CONSTRAINT FK_AEDAD51CF268C2C5 FOREIGN KEY (imported_from_exercise_id) REFERENCES exercise (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_AEDAD51CF268C2C5 ON exercise (imported_from_exercise_id)');

        $this->addSql('ALTER TABLE routine ADD imported_from_routine_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE routine ADD CONSTRAINT FK_4BF6D8D6AF883092 FOREIGN KEY (imported_from_routine_id) REFERENCES routine (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_4BF6D8D6AF883092 ON routine (imported_from_routine_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exercise DROP FOREIGN KEY FK_AEDAD51CF268C2C5');
        $this->addSql('DROP INDEX IDX_AEDAD51CF268C2C5 ON exercise');
        $this->addSql('ALTER TABLE exercise DROP imported_from_exercise_id');

        $this->addSql('ALTER TABLE routine DROP FOREIGN KEY FK_4BF6D8D6AF883092');
        $this->addSql('DROP INDEX IDX_4BF6D8D6AF883092 ON routine');
        $this->addSql('ALTER TABLE routine DROP imported_from_routine_id');
    }
}
