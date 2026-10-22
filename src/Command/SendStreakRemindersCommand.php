<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\History\HistoryStatsBuilder;
use App\Domain\Mail\UnsubscribeTokenGenerator;
use App\Entity\User;
use App\Repository\PracticeSessionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
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
 * Daily evening sweep (meant to run via a server crontab, see CLAUDE.md) -
 * reuses HistoryStatsBuilder::currentStreak() (the same computation that
 * already powers the 🔥 hint on Heute/Verlauf) rather than a second streak
 * implementation. Only ever emails an account that has genuinely opted in
 * by existing (logging in requires an email; anonymous visitors have none
 * to send to) and hasn't explicitly opted out via the mail's own
 * unsubscribe link.
 */
#[AsCommand(name: 'app:send-streak-reminders', description: 'Emails users with a 7+ day streak who have not practiced yet today')]
final class SendStreakRemindersCommand extends Command
{
    private const MIN_STREAK = 7;
    private const SESSION_WINDOW = 200;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PracticeSessionRepository $sessions,
        private readonly HistoryStatsBuilder $statsBuilder,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly UnsubscribeTokenGenerator $tokens,
        private readonly EntityManagerInterface $em,
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
            if ($user->hasOptedOutOfStreakReminders()) {
                continue;
            }
            if ($user->getLastStreakReminderSentAt() !== null && $user->getLastStreakReminderSentAt() >= $today) {
                continue;
            }

            $sessions = $this->sessions->forVisitor($user->getVisitorId(), self::SESSION_WINDOW);
            $stats = $this->statsBuilder->build($sessions);
            if ($stats['currentStreak'] < self::MIN_STREAK) {
                continue;
            }

            $practicedToday = false;
            foreach ($sessions as $session) {
                if ($session->isFinished() && $session->getFinishedAt()?->format('Y-m-d') === $today->format('Y-m-d')) {
                    $practicedToday = true;
                    break;
                }
            }
            if ($practicedToday) {
                continue;
            }

            $this->sendReminder($user, $stats['currentStreak']);
            $user->markStreakReminderSent($today);
            $sent++;
        }

        $this->em->flush();
        $io->writeln(sprintf('Sent %d streak reminder(s).', $sent));

        return Command::SUCCESS;
    }

    private function sendReminder(User $user, int $streak): void
    {
        $html = $this->twig->render('emails/streak_reminder.html.twig', [
            'firstName' => $user->getFirstName(),
            'streak' => $streak,
            'diaryUrl' => $this->baseUrl . '/tagebuch',
            'unsubscribeUrl' => $this->baseUrl . '/erinnerungen/abmelden/' . $user->getId() . '/' . $this->tokens->generate($user->getId()),
        ]);

        $email = (new Email())
            ->from(sprintf('%s <%s>', $this->fromName, $this->fromAddress))
            ->to($user->getEmail())
            ->subject($this->translator->trans('mail.streak_reminder.subject', ['%streak%' => $streak]))
            ->html($html);

        $this->mailer->send($email);
    }
}
