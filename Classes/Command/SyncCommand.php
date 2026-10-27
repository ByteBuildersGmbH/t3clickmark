<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Command;

use ByteBuilders\T3ClickMark\Service\AgencyDeskSyncService;
use ByteBuilders\T3ClickMark\Service\PendingDeliveryService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Full synchronisation with the ClickMark platform, meant for the TYPO3 scheduler
 * ("Execute console commands") or cron:
 *
 *  1. Push: deliver all feedback the platform has not confirmed yet (failed
 *     deliveries below --max-attempts plus never sent records), see
 *     PendingDeliveryService.
 *  2. Pull: fetch status changes, new tickets and comments from the platform
 *     (AgencyDeskSyncService). The pull is rate-limited to once per minute; a
 *     skipped pull is reported, not treated as an error.
 *
 * Exits non-zero only on real failures (deliveries failed, platform unreachable),
 * so the scheduler flags the run.
 *
 * Registered in Configuration/Commands.php as `clickmark:sync` (schedulable).
 */
final class SyncCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Synchronise feedback with the ClickMark platform (push undelivered feedback, pull status changes and comments).')
            ->addOption(
                'max-attempts',
                null,
                InputOption::VALUE_REQUIRED,
                'Skip failed records that already have this many delivery attempts',
                (string)PendingDeliveryService::DEFAULT_MAX_ATTEMPTS
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $exitCode = Command::SUCCESS;

        // 1. Push
        $push = GeneralUtility::makeInstance(PendingDeliveryService::class)
            ->deliverPending((int)$input->getOption('max-attempts'), true);

        if ($push['total'] > 0 && !$push['configured']) {
            $output->writeln('ClickMark is not configured (no API key) — nothing to synchronise.');
            return Command::SUCCESS;
        }

        if ($push['total'] === 0) {
            $output->writeln('Push: no undelivered feedback.');
        } else {
            $output->writeln(sprintf(
                'Push: %d delivered, %d failed (of %d undelivered).',
                $push['delivered'],
                $push['failed'],
                $push['total']
            ));
            foreach ($push['errors'] as $uid => $error) {
                $output->writeln(sprintf('  - feedback #%d: %s', $uid, $error), OutputInterface::VERBOSITY_VERBOSE);
            }
            if ($push['failed'] > 0) {
                $exitCode = Command::FAILURE;
            }
        }

        // 2. Pull
        $pull = GeneralUtility::makeInstance(AgencyDeskSyncService::class)->sync();
        switch ($pull['result']) {
            case AgencyDeskSyncService::RESULT_NOT_CONFIGURED:
                $output->writeln('ClickMark is not configured (no API key) — nothing to synchronise.');
                break;
            case AgencyDeskSyncService::RESULT_RATE_LIMITED:
                $output->writeln('Pull: skipped (rate limit — last pull less than 60 seconds ago).');
                break;
            case AgencyDeskSyncService::RESULT_ERROR:
                $output->writeln('<error>Pull: failed — ' . $pull['error'] . '</error>');
                $exitCode = Command::FAILURE;
                break;
            default:
                $output->writeln(sprintf('Pull: %d record(s) created/updated.', $pull['updated']));
        }

        return $exitCode;
    }
}
