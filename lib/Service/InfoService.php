<?php

namespace OCA\ServerInfo\Service;

use OCA\ServerInfo\DatabaseStatistics;
use OCA\ServerInfo\PhpStatistics;
use OCA\ServerInfo\SessionStatistics;
use OCA\ServerInfo\ShareStatistics;
use OCA\ServerInfo\StorageStatistics;
use OCA\ServerInfo\SystemStatistics;

class InfoService {
    private SystemStatistics $systemStatistics;
    private StorageStatistics $storageStatistics;
    private PhpStatistics $phpStatistics;
    private DatabaseStatistics $databaseStatistics;
    private ShareStatistics $shareStatistics;
    private SessionStatistics $sessionStatistics;

    public function __construct(
        SystemStatistics $systemStatistics,
        StorageStatistics $storageStatistics,
        PhpStatistics $phpStatistics,
        DatabaseStatistics $databaseStatistics,
        ShareStatistics $shareStatistics,
        SessionStatistics $sessionStatistics
    ) {
        $this->systemStatistics = $systemStatistics;
        $this->storageStatistics = $storageStatistics;
        $this->phpStatistics = $phpStatistics;
        $this->databaseStatistics = $databaseStatistics;
        $this->shareStatistics = $shareStatistics;
        $this->sessionStatistics = $sessionStatistics;
    }

    public function getServerInfo(bool $skipApps = true, bool $skipUpdate = true): array {
        return [
            'nextcloud' => [
                'system' => $this->systemStatistics->getSystemStatistics($skipApps, $skipUpdate),
                'storage' => $this->storageStatistics->getStorageStatistics(),
                'shares' => $this->shareStatistics->getShareStatistics(),
            ],
            'server' => [
                'webserver' => $this->getWebserver(),
                'php' => $this->phpStatistics->getPhpStatistics(),
                'database' => $this->databaseStatistics->getDatabaseStatistics(),
            ],
            'activeUsers' => $this->sessionStatistics->getSessionStatistics(),
        ];
    }

    /**
     * Get webserver information
     */
    private function getWebserver(): string {
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            return $_SERVER['SERVER_SOFTWARE'];
        }
        return 'unknown';
    }
}
