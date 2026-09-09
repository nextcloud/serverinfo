<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Controller;

use Doctrine\DBAL\DriverManager;
use OCA\ServerInfo\AppInfo\Application;
use OCA\ServerInfo\Database\Advisor;
use OCA\ServerInfo\Database\CheckSummary;
use OCA\ServerInfo\Database\DatabaseProbe;
use OCA\ServerInfo\Database\LiveMetrics;
use OCA\ServerInfo\Database\RuleSet;
use OCA\ServerInfo\Database\RuleSet\Mysql as MysqlRuleSet;
use OCA\ServerInfo\Database\RuleSet\Postgres as PostgresRuleSet;
use OCA\ServerInfo\Database\Snapshot;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\Security\ICredentialsManager;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * The database check page.
 *
 * Admin-only by the app framework's default: none of these methods carries
 * NoAdminRequired, so the security middleware rejects everyone else.
 */
class DatabaseController extends Controller {
	private const DRIVERS = ['pdo_mysql', 'pdo_pgsql'];

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private ICredentialsManager $credentials,
		private ISession $session,
		private DatabaseProbe $probe,
		private LiveMetrics $liveMetrics,
		private Advisor $advisor,
		private CheckSummary $summary,
		private MysqlRuleSet $mysqlRules,
		private PostgresRuleSet $postgresRules,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Run the checks against a fresh snapshot.
	 *
	 * Synchronous on purpose: a snapshot plus ~70 rule evaluations finishes in
	 * well under a second, and a background queue would only add latency. The
	 * page asks once when it is first opened, so there is nothing to rate-limit
	 * beyond what admin-only access already gives us.
	 */
	public function check(): JSONResponse {
		// PHP holds the session lock for the whole request, and this one takes
		// seconds while the Status page keeps polling every two. Without letting
		// go first, those polls queue up behind it and can exhaust the web
		// server's workers. Nothing below writes to the session.
		$this->session->close();

		try {
			$snapshot = $this->probe->snapshot();
		} catch (\Throwable $e) {
			$this->logger->error('serverinfo: database snapshot failed: {msg}', [
				'msg' => $e->getMessage(), 'app' => Application::APP_ID, 'exception' => $e,
			]);

			return new JSONResponse([
				'supported' => false,
				'reason' => 'error',
				'message' => $e->getMessage(),
			]);
		}

		if (!$snapshot->isSupported()) {
			$this->summary->record(false, []);

			return new JSONResponse([
				'supported' => false,
				'reason' => 'flavour',
				'flavour' => $snapshot->flavour,
			]);
		}

		$results = array_map(
			static fn ($result) => $result->jsonSerialize(),
			$this->advisor->run($this->ruleSetFor($snapshot), $snapshot),
		);
		// Opening this page is what keeps the Overview's setup check current, so
		// acting on a finding here clears the warning there without a wait.
		$this->summary->record(true, $results);

		return new JSONResponse([
			'supported' => true,
			'flavour' => $snapshot->flavour,
			'version' => $snapshot->version,
			'ranAt' => time(),
			'results' => $results,
		]);
	}

	/**
	 * Connections, throughput and round-trip latency in one response.
	 *
	 * Deliberately one endpoint rather than two: each poll costs a full
	 * Nextcloud request cycle, which dwarfs the queries themselves, so asking
	 * for the metrics and timing the round trip together halves the traffic.
	 */
	public function live(): JSONResponse {
		// Held for the length of the request otherwise, which would make the
		// page's own polling queue up behind it. Nothing below writes to it.
		$this->session->close();

		$started = microtime(true);
		try {
			$this->probe->ping();
			$latencyMs = round((microtime(true) - $started) * 1000.0, 2);
		} catch (\Throwable) {
			$latencyMs = null;
		}

		try {
			$metrics = $this->liveMetrics->collect();
		} catch (\Throwable $e) {
			$this->logger->debug('serverinfo: live database metrics failed: {msg}', [
				'msg' => $e->getMessage(), 'app' => Application::APP_ID,
			]);

			return new JSONResponse(['available' => false, 'latencyMs' => $latencyMs]);
		}

		return new JSONResponse([
			'available' => true,
			'latencyMs' => $latencyMs,
			'connections' => $metrics['connections'],
			'throughput' => $metrics['throughput'],
			'threadsRunning' => $metrics['threadsRunning'],
		]);
	}

	/**
	 * The optional connection used instead of Nextcloud's own, so that the
	 * checks can be run as a user with rights Nextcloud's does not need.
	 */
	public function settings(): JSONResponse {
		return new JSONResponse(['override' => $this->override()]);
	}

	#[PasswordConfirmationRequired]
	public function updateSettings(
		?string $host = null,
		?int $port = null,
		?string $user = null,
		?string $database = null,
		?string $driver = null,
		#[SensitiveParameter]
		?string $password = null,
		?bool $clearPassword = false,
	): JSONResponse {
		if ($host !== null) {
			$host = trim($host);
			if ($host !== '' && !$this->probe->isHostAllowed($host)) {
				return new JSONResponse([
					'message' => 'This host is not on serverinfo.allowed_override_hosts in config.php.',
				], Http::STATUS_FORBIDDEN);
			}
			$this->config->setAppValue(Application::APP_ID, 'override.host', $host);
		}
		if ($port !== null) {
			$this->config->setAppValue(Application::APP_ID, 'override.port', (string)max(0, $port));
		}
		if ($user !== null) {
			$this->config->setAppValue(Application::APP_ID, 'override.user', trim($user));
		}
		if ($database !== null) {
			$this->config->setAppValue(Application::APP_ID, 'override.database', trim($database));
		}
		if ($driver !== null && in_array($driver, self::DRIVERS, true)) {
			$this->config->setAppValue(Application::APP_ID, 'override.driver', $driver);
		}

		if ($password !== null && $password !== '') {
			$this->credentials->store('', Application::APP_ID . ':override.password', $password);
		} elseif ($clearPassword === true) {
			$this->credentials->delete('', Application::APP_ID . ':override.password');
		}

		return new JSONResponse(['override' => $this->override()]);
	}

	/**
	 * Dials the supplied parameters without storing them, so an admin can find
	 * out whether they work before committing to them.
	 */
	#[PasswordConfirmationRequired]
	public function testConnection(
		string $host,
		int $port,
		string $user,
		#[SensitiveParameter]
		string $password,
		string $database,
		string $driver = 'pdo_mysql',
	): JSONResponse {
		if (!in_array($driver, self::DRIVERS, true)) {
			return new JSONResponse(['ok' => false, 'message' => 'Unsupported driver.'], Http::STATUS_BAD_REQUEST);
		}
		if (!$this->probe->isHostAllowed($host)) {
			return new JSONResponse([
				'ok' => false,
				'message' => 'This host is not on serverinfo.allowed_override_hosts in config.php.',
			], Http::STATUS_FORBIDDEN);
		}

		try {
			$connection = DriverManager::getConnection([
				'driver' => $driver,
				'host' => $host,
				'port' => $port > 0 ? $port : null,
				'user' => $user,
				'password' => $password,
				'dbname' => $database,
			]);
			$connection->connect();
			$version = $connection->fetchOne($driver === 'pdo_pgsql' ? 'SHOW server_version' : 'SELECT VERSION()');
			$connection->close();

			return new JSONResponse(['ok' => true, 'version' => (string)$version]);
		} catch (\Throwable $e) {
			return new JSONResponse(['ok' => false, 'message' => $e->getMessage()]);
		}
	}

	/**
	 * @return array<string, mixed> the stored override, never the password
	 */
	private function override(): array {
		$host = $this->config->getAppValue(Application::APP_ID, 'override.host', '');

		return [
			'host' => $host,
			'port' => (int)$this->config->getAppValue(Application::APP_ID, 'override.port', '0'),
			'user' => $this->config->getAppValue(Application::APP_ID, 'override.user', ''),
			'database' => $this->config->getAppValue(Application::APP_ID, 'override.database', ''),
			'driver' => $this->config->getAppValue(Application::APP_ID, 'override.driver', 'pdo_mysql'),
			'passwordSet' => $host !== ''
				&& $this->credentials->retrieve('', Application::APP_ID . ':override.password') !== null,
		];
	}

	private function ruleSetFor(Snapshot $snapshot): RuleSet {
		return match ($snapshot->flavour) {
			Snapshot::FLAVOUR_PGSQL => $this->postgresRules,
			default => $this->mysqlRules,
		};
	}
}
