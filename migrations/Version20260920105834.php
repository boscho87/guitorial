<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920105834 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds a unique (visitor_id, kind) constraint on exercise_group, preventing the duplicate system groups a request race could create (ExerciseGroupProvisioner::systemGroupFor() - two concurrent requests both provisioning a missing "Praxis"/"Theorie" group at once). First merges any duplicate already in prod: exercise_version rows on a losing duplicate move to the lowest-id row for that (visitor_id, kind), then the losing duplicate(s) are removed.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE exercise_version ev
            JOIN exercise_group g ON g.id = ev.group_id AND g.kind IS NOT NULL
            JOIN (
                SELECT visitor_id, kind, MIN(id) AS winner_id
                FROM exercise_group
                WHERE kind IS NOT NULL
                GROUP BY visitor_id, kind
            ) w ON w.visitor_id = g.visitor_id AND w.kind = g.kind
            SET ev.group_id = w.winner_id
            WHERE g.id <> w.winner_id
            SQL);
        $this->addSql(<<<'SQL'
            DELETE g FROM exercise_group g
            JOIN (
                SELECT visitor_id, kind, MIN(id) AS winner_id
                FROM exercise_group
                WHERE kind IS NOT NULL
                GROUP BY visitor_id, kind
            ) w ON w.visitor_id = g.visitor_id AND w.kind = g.kind
            WHERE g.kind IS NOT NULL AND g.id <> w.winner_id
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_48F6B30770BEE6D3BC4BCD9 ON exercise_group (visitor_id, kind)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_48F6B30770BEE6D3BC4BCD9 ON exercise_group');
    }
}
