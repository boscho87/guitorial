<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011204715 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Adds exercise.practice_tools_snapshot, so an exercise remembers its own metronome/drone setup (bpm, beats, droneNote, fifth, octave) across sessions.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exercise ADD practice_tools_snapshot JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exercise DROP practice_tools_snapshot');
    }
}
