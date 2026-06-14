<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Command;

use ByteBuilders\T3ClickMark\Service\AgencyDeskForwardingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Re-delivers feedback that failed to reach the ClickMark platform (e.g. during
 * a platform deploy or outage). Meant to run via cron / the TYPO3 scheduler, so
 * delivery does not depend on a human opening the backend module.
 *
 * Registered in Configuration/Commands.php as `clickmark:deliver-pending`
 * (schedulable). Only records with forward_status='failed' and no external_id
 * are retried, capped at --max-attempts.
 */
final class DeliverPendingFeedbackCommand extends Command
{
    private const MAX_ATTEMPTS = 20;
    private const TABLE = 'tx_t3clickmark_domain_model_feedback';

    protected function configure(): void
    {
        $this
            ->setDescription('Re-deliver feedback that failed to reach the ClickMark platform.')
            ->addOption(
                'max-attempts',
                null,
                InputOption::VALUE_REQUIRED,
                'Skip records that already have this many delivery attempts',
                (string)self::MAX_ATTEMPTS
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $maxAttempts = (int)$input->getOption('max-attempts');

        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);
        $uids = $qb->select('uid')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('forward_status', $qb->createNamedParameter('failed')),
                $qb->expr()->eq('external_id', $qb->createNamedParameter('')),
                $qb->expr()->lt('forward_attempts', $qb->createNamedParameter($maxAttempts, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchFirstColumn();

        if (empty($uids)) {
            $output->writeln('No pending feedback to deliver.');
            return Command::SUCCESS;
        }

        $forwarding = GeneralUtility::makeInstance(AgencyDeskForwardingService::class);
        $delivered = 0;
        $stillFailing = 0;

        foreach ($uids as $uid) {
            $result = $forwarding->forwardFeedback((int)$uid);
            if ($result === null) {
                // AgencyDesk not configured — nothing this command can do.
                $output->writeln('AgencyDesk is not configured (no API key) — nothing to deliver.');
                return Command::SUCCESS;
            }
            if (!empty($result['forwarded'])) {
                $delivered++;
            } else {
                $stillFailing++;
            }
        }

        $output->writeln(sprintf(
            'Delivered %d, still failing %d (of %d pending).',
            $delivered,
            $stillFailing,
            count($uids)
        ));

        return Command::SUCCESS;
    }
}
