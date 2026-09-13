<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Keine Gruppe" stops being a state an exercise can be in: every exercise
 * without a group gets backfilled into one of two new per-visitor system
 * groups (Theorie/Praxis), based on whether it currently has an
 * internal-tool resource. Written as a PHP-driven backfill (not a single
 * SQL statement) since "get or create this visitor's Theorie/Praxis group"
 * needs real per-row branching - see ExerciseGroupProvisioner, which
 * implements the exact same lookup-by-kind rule for new exercises going
 * forward.
 */
final class Version20260913200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds exercise_group.kind and backfills every group-less exercise_version into a per-visitor Theorie/Praxis system group.';
    }

    public function up(Schema $schema): void
    {
        // Run immediately (not via addSql(), which only executes after
        // up() returns) - the PHP-driven backfill below needs the column
        // to already exist.
        $this->connection->executeStatement('ALTER TABLE exercise_group ADD kind VARCHAR(20) DEFAULT NULL');

        $affected = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT e.visitor_id AS visitor_id, e.id AS exercise_id
             FROM exercise_version ev
             JOIN exercise e ON e.id = ev.exercise_id
             WHERE ev.group_id IS NULL',
        );

        $groupIdByVisitorAndKind = [];
        $sortIndexByVisitor = [];

        foreach ($affected as $row) {
            $visitorId = $row['visitor_id'];
            $exerciseId = (int) $row['exercise_id'];

            $hasInternalTool = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM exercise_resource WHERE exercise_id = ? AND type = ?',
                [$exerciseId, 'internal_tool'],
            ) > 0;
            $kind = $hasInternalTool ? 'theorie' : 'praxis';
            $label = $hasInternalTool ? 'Theorie' : 'Praxis';

            $cacheKey = $visitorId.'|'.$kind;
            if (!isset($groupIdByVisitorAndKind[$cacheKey])) {
                $existingGroupId = $this->connection->fetchOne(
                    'SELECT id FROM exercise_group WHERE visitor_id = ? AND kind = ?',
                    [$visitorId, $kind],
                );

                if ($existingGroupId !== false) {
                    $groupIdByVisitorAndKind[$cacheKey] = (int) $existingGroupId;
                } else {
                    if (!isset($sortIndexByVisitor[$visitorId])) {
                        $sortIndexByVisitor[$visitorId] = (int) $this->connection->fetchOne(
                            'SELECT COUNT(*) FROM exercise_group WHERE visitor_id = ?',
                            [$visitorId],
                        );
                    }
                    $sortIndex = $sortIndexByVisitor[$visitorId]++;

                    $this->connection->executeStatement(
                        'INSERT INTO exercise_group (visitor_id, name, sort_index, kind, created_at) VALUES (?, ?, ?, ?, NOW())',
                        [$visitorId, $label, $sortIndex, $kind],
                    );
                    $groupIdByVisitorAndKind[$cacheKey] = (int) $this->connection->lastInsertId();
                }
            }

            // Same "all versions at once" rule as a group deletion's
            // reassignment - past and present versions of this exercise
            // move together, never fragmented across two groups.
            $this->connection->executeStatement(
                'UPDATE exercise_version SET group_id = ? WHERE exercise_id = ? AND group_id IS NULL',
                [$groupIdByVisitorAndKind[$cacheKey], $exerciseId],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exercise_group DROP kind');
    }
}
