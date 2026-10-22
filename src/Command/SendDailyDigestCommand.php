<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Diary\ExerciseDayViewBuilder;
use App\Domain\Diary\RoutineProvisioner;
use App\Entity\User;
use App\Repository\ExerciseVersionRepository;
use App\Repository\PracticeSessionRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Daily morning sweep (meant to run via a server crontab, e.g. 7:00, see
 * CLAUDE.md) - opt-in only (User::dailyDigestEnabled, default off, unlike
 * the streak reminder), since a daily mail is a bigger commitment than the
 * occasional streak warning. Reuses the exact same rows Heute itself shows
 * (ExerciseDayViewBuilder::buildDayForRoutine(), the same recipe
 * DiaryController::index() uses) rather than a second "what's due today"
 * computation. Skips entirely when there's nothing outstanding - no
 * routine, or everything already done before the mail went out.
 */
#[AsCommand(name: 'app:send-daily-digest', description: "Emails opted-in users a summary of today's outstanding exercises")]
final class SendDailyDigestCommand extends Command
{
    private const MAX_ITEMS_SHOWN = 5;

    public function __construct(
        private readonly UserRepository $users,
        private readonly ExerciseVersionRepository $versions,
        private readonly PracticeSessionRepository $sessions,
        private readonly RoutineProvisioner $routineProvisioner,
        private readonly ExerciseDayViewBuilder $dayViewBuilder,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        #[Autowire('%app.mailer_from_address%')]
        private readonly string $fromAddress,
        #[Autowire('%app.mailer_from_name%')]
        private readonly string $fromName,
        #[Autowire('%app.base_url%')]
        private readonly string $baseUrl,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $today = new \DateTimeImmutable('today');
        $sent = 0;

        foreach ($this->users->findAll() as $user) {
            if (!$user->hasDailyDigestEnabled()) {
                continue;
            }

            $activeRoutine = $this->routineProvisioner->ensureActiveRoutine($user->getVisitorId());
            if ($activeRoutine === null) {
                continue;
            }

            $allVersions = $this->versions->forVisitor($user->getVisitorId());
            $allSessions = $this->sessions->forVisitor($user->getVisitorId(), 300);
            $rows = $this->dayViewBuilder->buildDayForRoutine($activeRoutine, $allVersions, $allSessions, $today);

            $titles = [];
            foreach ($rows as $row) {
                if (!$row['checked']) {
                    $titles[] = $row['version']->getTitle();
                }
            }
            if ($titles === []) {
                continue;
            }

            $this->sendDigest($user, $titles);
            $sent++;
        }

        $io->writeln(sprintf('Sent %d daily digest(s).', $sent));

        return Command::SUCCESS;
    }

    /**
     * @param string[] $titles
     */
    private function sendDigest(User $user, array $titles): void
    {
        $shown = array_slice($titles, 0, self::MAX_ITEMS_SHOWN);
        $moreCount = count($titles) - count($shown);

        $html = $this->twig->render('emails/daily_digest.html.twig', [
            'firstName' => $user->getFirstName(),
            'items' => $shown,
            'moreCount' => $moreCount,
            'diaryUrl' => $this->baseUrl . '/tagebuch',
            'profileUrl' => $this->baseUrl . '/profil',
        ]);

        $email = (new Email())
            ->from(sprintf('%s <%s>', $this->fromName, $this->fromAddress))
            ->to($user->getEmail())
            ->subject($this->translator->trans('mail.daily_digest.subject'))
            ->html($html);

        $this->mailer->send($email);
    }
}
