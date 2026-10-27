<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Command;

use ByteBuilders\T3ClickMark\Service\PendingDeliveryService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Re-delivers feedback that failed to reach the ClickMark platform (e.g. during
 * a platform deploy or outage). Meant to run via cron / the TYPO3 scheduler, so
 * delivery does not depend on a human opening the backend module.
 *
 * Registered in Configuration/Commands.php as `clickmark:deliver-pending`
 * (schedulable). Only records with forward_status='failed' and no external_id
 * are retried, capped at --max-attempts.
 *
 * Kept for existing scheduler tasks — `clickmark:sync` covers this plus never
 * sent records and the pull from the platform.
 */
final class DeliverPendingFeedbackCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Re-deliver feedback that failed to reach the ClickMark platform (see also clickmark:sync).')
            ->addOption(
                'max-attempts',
                null,
                InputOption::VALUE_REQUIRED,
                'Skip records that already have this many delivery attempts',
                (string)PendingDeliveryService::DEFAULT_MAX_ATTEMPTS
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = GeneralUtility::makeInstance(PendingDeliveryService::class)
            ->deliverPending((int)$input->getOption('max-attempts'), false);

        if ($result['total'] === 0) {
            $output->writeln('No pending feedback to deliver.');
            return Command::SUCCESS;
        }
        if (!$result['configured']) {
            $output->writeln('AgencyDesk is not configured (no API key) — nothing to deliver.');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            'Delivered %d, still failing %d (of %d pending).',
            $result['delivered'],
            $result['failed'],
            $result['total']
        ));

        return Command::SUCCESS;
    }
}
