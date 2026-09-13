<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds points/time_bonus_points to practice_session for the points-based personal-records system (base points per correct answer, plus a time bonus on a perfect round that beats the visitor\'s own prior fastest same-question-count time) - purely additive, existing rows keep NULL (pre-dates the feature, excluded from record comparisons).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE practice_session ADD points INT DEFAULT NULL, ADD time_bonus_points INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE practice_session DROP points, DROP time_bonus_points');
    }
}
