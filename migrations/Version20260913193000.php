<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the routine/routine_item tables (an ordered list of existing exercises the visitor wants to work through as one unit).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE routine (id INT AUTO_INCREMENT NOT NULL, visitor_id VARCHAR(36) NOT NULL, name VARCHAR(80) NOT NULL, sort_index INT NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_4BF6D8D670BEE6D (visitor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE routine_item (id INT AUTO_INCREMENT NOT NULL, routine_id INT NOT NULL, exercise_id INT NOT NULL, sort_index INT NOT NULL, INDEX IDX_5AFD726F27A94C7 (routine_id), INDEX IDX_5AFD726E934951A (exercise_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE routine_item ADD CONSTRAINT FK_ROUTINE_ITEM_ROUTINE FOREIGN KEY (routine_id) REFERENCES routine (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE routine_item ADD CONSTRAINT FK_ROUTINE_ITEM_EXERCISE FOREIGN KEY (exercise_id) REFERENCES exercise (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE routine_item DROP FOREIGN KEY FK_ROUTINE_ITEM_ROUTINE');
        $this->addSql('ALTER TABLE routine_item DROP FOREIGN KEY FK_ROUTINE_ITEM_EXERCISE');
        $this->addSql('DROP TABLE routine_item');
        $this->addSql('DROP TABLE routine');
    }
}
