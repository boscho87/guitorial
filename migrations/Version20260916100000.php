<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260916100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds routine.is_active - at most one Routine per visitor is active, determining what Heute shows.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE routine ADD is_active TINYINT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE routine DROP is_active');
    }
}
