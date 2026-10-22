<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ExerciseRepository;
use App\Repository\PracticeSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Daily sweep (meant to run via a server crontab, see CLAUDE.md) that
 * deactivates any exercise with no finished session in the last
 * STALE_AFTER_DAYS days - a flat cutoff regardless of its own rhythm
 * (daily/weekly/none), deliberately kept simple for now. An exercise with
 * no finished session at all yet falls back to its createdAt, so a newly
 * added but never-practiced exercise also eventually goes quiet instead of
 * sitting there forever. Reversed the moment it's practiced again
 * (SessionRecorder::start()) - this command never un-deactivates anything.
 */
#[AsCommand(name: 'app:deactivate-stale-exercises', description: 'Deactivates exercises with no finished session in the last 10 days')]
final class DeactivateStaleExercisesCommand extends Command
{
    private const STALE_AFTER_DAYS = 14;

    public function __construct(
        private readonly ExerciseRepository $exercises,
        private readonly PracticeSessionRepository $sessions,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $today = new \DateTimeImmutable('today');
        $cutoff = $today->modify(sprintf('-%d days', self::STALE_AFTER_DAYS));

        $lastFinishedByExercise = $this->sessions->latestFinishedAtByExercise();

        $deactivated = 0;
        foreach ($this->exercises->findActive() as $exercise) {
            $lastPracticedOn = $lastFinishedByExercise[$exercise->getId()] ?? $exercise->getCreatedAt();
            if ($lastPracticedOn >= $cutoff) {
                continue;
            }

            $exercise->deactivate($today);
            $deactivated++;
        }

        $this->em->flush();

        $io->writeln(sprintf('Deactivated %d exercise(s) unused for %d+ days.', $deactivated, self::STALE_AFTER_DAYS));

        return Command::SUCCESS;
    }
}
