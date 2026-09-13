<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913201500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds practice_session.note - a free-text note about one Durchführung, distinct from the durable Exercise note.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE practice_session ADD note LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE practice_session DROP note');
    }
}
