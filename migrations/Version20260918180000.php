<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260918180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds routine.share_token - a lazily-generated public link so a Routine can be shared and imported by a logged-in visitor.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE routine ADD share_token VARCHAR(20) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4BF6D8D6D6594DD6 ON routine (share_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_4BF6D8D6D6594DD6 ON routine');
        $this->addSql('ALTER TABLE routine DROP share_token');
    }
}
