<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Manages the connection state between this TYPO3 instance and ClickMark AgencyDesk.
 * The interactive OAuth flow stores its connection (API key, project ID, dashboard
 * URL) in the TYPO3 Registry. A manually configured key in the Extension
 * Configuration (legacy) is honoured as a fallback so both paths report the same
 * connection state and resolve to the same key.
 */
class ClickMarkConnectionService
{
    private const REGISTRY_NAMESPACE = 'tx_t3clickmark';

    public function isConnected(): bool
    {
        return $this->getApiKey() !== '';
    }

    /**
     * Returns the API key used to talk to the platform: the OAuth-connected key
     * (Registry) takes precedence; a manually configured Extension Configuration
     * key is the legacy fallback.
     */
    public function getApiKey(): string
    {
        $key = (string)$this->getRegistry()->get(self::REGISTRY_NAMESPACE, 'connected_api_key', '');
        if ($key !== '') {
            return $key;
        }

        try {
            return trim((string)GeneralUtility::makeInstance(ExtensionConfiguration::class)
                ->get('t3clickmark', 'apiKey'));
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function getProjectId(): int
    {
        return (int)$this->getRegistry()->get(self::REGISTRY_NAMESPACE, 'connected_project_id', 0);
    }

    public function getDashboardUrl(): string
    {
        return (string)$this->getRegistry()->get(self::REGISTRY_NAMESPACE, 'connected_dashboard_url', '');
    }

    /**
     * Store connection details after successful OAuth exchange.
     */
    public function storeConnection(string $apiKey, int $projectId, string $dashboardUrl): void
    {
        $registry = $this->getRegistry();
        $registry->set(self::REGISTRY_NAMESPACE, 'connected_api_key', $apiKey);
        $registry->set(self::REGISTRY_NAMESPACE, 'connected_project_id', $projectId);
        $registry->set(self::REGISTRY_NAMESPACE, 'connected_dashboard_url', $dashboardUrl);
    }

    /**
     * Remove all connection data (disconnect from AgencyDesk).
     */
    public function disconnect(): void
    {
        $registry = $this->getRegistry();
        $registry->remove(self::REGISTRY_NAMESPACE, 'connected_api_key');
        $registry->remove(self::REGISTRY_NAMESPACE, 'connected_project_id');
        $registry->remove(self::REGISTRY_NAMESPACE, 'connected_dashboard_url');
    }

    private function getRegistry(): Registry
    {
        return GeneralUtility::makeInstance(Registry::class);
    }
}
