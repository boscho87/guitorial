<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One-time data fix for the "1232 von 10 Min." bug: a manual/external
 * exercise session left running for hours (no heartbeat existed yet) and
 * finished much later credited its entire real-world wall-clock gap.
 * Clips any already-finished session whose duration exceeds
 * PracticeSession::MAX_SESSION_MINUTES down to that same ceiling - the
 * same backstop finish() now applies going forward.
 */
final class Version20260914090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clips already-finished practice_session rows whose duration exceeds the new 180-minute session cap.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE practice_session
             SET finished_at = DATE_ADD(started_at, INTERVAL 180 MINUTE)
             WHERE finished_at IS NOT NULL
               AND TIMESTAMPDIFF(MINUTE, started_at, finished_at) > 180',
        );
    }

    public function down(Schema $schema): void
    {
        // Deliberately irreversible - the original (bogus) finished_at values are not recoverable.
    }
}
