<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written data backfill, an explicit exception to the usual pure-diff
 * migration convention (see Version20260909211835 for the first such
 * exception): copies every diary_item row into the new exercise/
 * exercise_version model so existing visitors keep their plan without any
 * action on their part.
 *
 * For each visitor, at most one exercise_group is created per distinct
 * diary_item.category value they actually used ("theorie"/"praxis" ->
 * "Theorie"/"Praxis"), skipped entirely if the visitor already has 6 groups
 * (the hard cap) - documented edge case, those items simply stay groupless.
 * diary_check rows are deliberately NOT migrated into practice_session: a
 * historical checkmark says "done", never "for how long", and inventing a
 * duration would be fabricating data. diary_item/diary_check themselves are
 * left in place and untouched (read-only from here on) - dropping them is a
 * separate, later migration once the new model has run for a while.
 */
final class Version20260909214500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfills exercise/exercise_group/exercise_version/exercise_resource from the existing diary_item table (hand-written data migration, diary_item/diary_check are left untouched).';
    }

    public function up(Schema $schema): void
    {
        $items = $this->connection->fetchAllAssociative('SELECT * FROM diary_item ORDER BY id ASC');
        if ($items === []) {
            return;
        }

        $groupIdByVisitorCategory = [];
        $groupCountByVisitor = [];
        $categoryLabels = ['theorie' => 'Theorie', 'praxis' => 'Praxis'];

        foreach ($items as $item) {
            $visitorId = $item['visitor_id'];
            $category = $item['category'];
            $key = $visitorId . ':' . $category;
            if (array_key_exists($key, $groupIdByVisitorCategory)) {
                continue;
            }

            $existingCount = $groupCountByVisitor[$visitorId]
                ?? (int) $this->connection->fetchOne('SELECT COUNT(*) FROM exercise_group WHERE visitor_id = ?', [$visitorId]);

            if ($existingCount >= 6) {
                // Documented edge case: visitor already has the max 6 groups
                // from other means - their migrated items stay groupless
                // rather than silently exceeding the cap.
                $groupIdByVisitorCategory[$key] = null;
                $groupCountByVisitor[$visitorId] = $existingCount;
                continue;
            }

            $this->connection->executeStatement(
                'INSERT INTO exercise_group (visitor_id, name, sort_index, created_at) VALUES (?, ?, ?, NOW())',
                [$visitorId, $categoryLabels[$category] ?? $category, $existingCount],
            );
            $groupIdByVisitorCategory[$key] = (int) $this->connection->lastInsertId();
            $groupCountByVisitor[$visitorId] = $existingCount + 1;
        }

        foreach ($items as $item) {
            $groupId = $groupIdByVisitorCategory[$item['visitor_id'] . ':' . $item['category']] ?? null;

            $this->connection->executeStatement(
                'INSERT INTO exercise (visitor_id, note, archived_at, created_at) VALUES (?, NULL, NULL, ?)',
                [$item['visitor_id'], $item['created_at']],
            );
            $exerciseId = (int) $this->connection->lastInsertId();

            $this->connection->executeStatement(
                'INSERT INTO exercise_version (exercise_id, group_id, title, target_minutes, repeat_rhythm, repeat_count, valid_from, valid_until, created_at)
                 VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?)',
                [$exerciseId, $groupId, $item['label'], $item['target_minutes'], 'ohne_vorgabe', $item['valid_from'], $item['valid_until'], $item['created_at']],
            );

            if ($item['linked_tool'] !== null) {
                $this->connection->executeStatement(
                    'INSERT INTO exercise_resource (exercise_id, type, sort_index, internal_tool_id, default_config_snapshot, external_url, external_provider, external_title, created_at)
                     VALUES (?, ?, 0, ?, NULL, NULL, NULL, NULL, ?)',
                    [$exerciseId, 'internal_tool', $item['linked_tool'], $item['created_at']],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Irreversible by design: exercise rows created here may since have
        // been edited/forked by the visitor, so there is no safe way to
        // tell "still exactly as migrated" apart from "since changed".
        // diary_item/diary_check were never touched, so a rollback simply
        // means re-running this migration is unsafe to repeat, not that
        // data was lost.
        $this->throwIrreversibleMigrationException('This is a one-way data backfill; diary_item/diary_check remain untouched as a permanent source of truth for pre-migration history.');
    }
}
