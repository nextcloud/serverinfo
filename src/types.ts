/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** The pages the admin settings can show, selected by the navigation at the top. */
export type SettingsView = 'status' | 'database' | 'background-jobs' | 'os'

/** What a failing check suggests changing, and where. */
export interface DatabaseApply {
	variable: string
	recommendedValue: string
	runtimeWritable: boolean
	configKey: string
	configFile: string
	note: string | null
}

/** One database check, as returned by /database/check. */
export interface DatabaseRule {
	id: string
	name: string
	category: string
	severity: 'alert' | 'warning' | 'notice'
	status: 'ok' | 'fail' | 'skipped'
	issue: string
	recommendation: string
	justification: string | null
	value: number | null
	skipReason: string | null
	apply: DatabaseApply | null
	details?: string[] | null
	docUrl: string | null
}

export type DatabaseCheck
	= | { supported: true, flavour: string, version: string, ranAt: number, results: DatabaseRule[] }
		| { supported: false, reason: 'flavour', flavour: string }
		| { supported: false, reason: 'error', message: string }

/** The optional connection the checks may use instead of Nextcloud's own. */
export interface DatabaseOverride {
	host: string
	port: number
	user: string
	database: string
	driver: string
	passwordSet: boolean
}

export interface ThermalZone {
	zone: string
	type: string
	temp: number
}

/** Payload of /update, polled every two seconds */
export interface LiveData {
	cpu: { load: number[] | false }
	memory: { total: number, free: number, swap_total: number, swap_free: number }
	servertime: string
	uptime: string
	thermalzones: ThermalZone[]
}

/** The OPcache segment is per PHP master process: other FPM pools or CLI have their own. */
export type OpcacheStatus
	= | { status: 'not_loaded' | 'disabled' | 'api_restricted' | 'status_unavailable' }
		| {
			status: 'ok'
			/** Bytes */
			memory: { used: number, wasted: number, free: number, total: number }
			/** Bytes */
			internedStrings: { used: number, free: number, total: number }
			keys: { used: number, max: number }
			hitRate: number
			cachedScripts: number
			oomRestarts: number
			cacheFull: boolean
			/** Seconds */
			revalidateFreq: number
			validateTimestamps: boolean
			/** Unix timestamp on the server clock, null if the cache has never restarted */
			lastRestart: number | null
			jit: { enabled: boolean, bufferUsed: number, bufferTotal: number } | null
		}

export type JobStatus = 'RUNNING' | 'SUCCEEDED' | 'FAILED' | 'CRASHED'

export interface JobRun {
	runId: string
	className: string
	serverId: number
	pid: number
	/** Unix timestamp on the server clock */
	startedAt: number
	status: JobStatus
	/** Milliseconds */
	duration: number | null
	/** Kilobytes, base 10 */
	memoryPeak: number | null
}

/** One job class aggregated over every retained run */
export interface SlowJob {
	className: string
	runs: number
	/** Milliseconds */
	avgDuration: number
	/** Milliseconds */
	maxDuration: number
	/** Kilobytes, base 10 */
	memoryPeak: number
}

/** Payload of /periodic, polled every minute */
export interface PeriodicData {
	cron: {
		mode: string
		lastRun: number
		/** Server clock, so job ages never depend on the browser's */
		now: number
	}
	backgroundJobs: {
		recent: JobRun[]
		failures: JobRun[]
		/** Empty until the collecting job has run once */
		slowest: SlowJob[]
		/** Runs older than this are pruned, bounding what "no failures" means */
		retentionDays: number
	}
}

export type OsLevel = 'ok' | 'info' | 'warning' | 'error' | 'unknown'

export type OsConcernId = 'updates' | 'reboot' | 'restart' | 'support' | 'package-lists'
	| 'automatic-updates' | 'failed-units' | 'time-sync' | 'helper'

export interface OsConcern {
	id: OsConcernId
	level: OsLevel
	facts: Record<string, string | number | boolean | string[] | null>
}

export interface OsPendingUpdate {
	name: string
	installed: string | null
	candidate: string
	security: boolean
	origin: string | null
	advisories: string[]
	severity: string | null
	/** Unix timestamp the update was first seen pending */
	since: number | null
	kind: 'package' | 'patch'
	/** One of the packages Nextcloud itself depends on */
	stack: boolean
	/** What a SUSE patch fixes, e.g. "Security update for glibc" */
	summary: string | null
}

export interface OsRebootReason {
	kind: 'kernel' | 'kernel-removed' | 'distribution' | 'core-library' | 'microcode'
	detail: string | null
	packages: string[]
	since: number | null
}

export type OsSupportState = 'supported' | 'extended' | 'extended-available' | 'ended' | 'rolling' | 'unknown'

export type OsHelperState = 'absent' | 'ok' | 'stale' | 'previous-boot' | 'other-host' | 'insecure' | 'unreadable' | 'invalid' | 'unsupported'

/** The collected operating system status, see OCA\ServerInfo\OsStatus\OsStatus */
export interface OsStatus {
	state: 'ok' | 'restricted' | 'unsupported'
	collectedAt: number
	release: { id: string, name: string, prettyName: string, versionId: string | null, codename: string | null } | null
	kernel: string
	architecture: string
	environment: { kind: 'host' | 'application-container' | 'system-container' | 'snap', container: string | null, virtualization: string | null }
	support: {
		rolling: boolean
		securityEnds: string | null
		extendedEnds: string | null
		extended: string | null
		extendedActive: boolean | null
		ltsFrom: string | null
		dataDate: string | null
	}
	packageManager: string | null
	updates: OsPendingUpdate[] | null
	updatesSource: 'system' | 'helper' | null
	securityClassified: boolean
	updatesOmitted: number
	held: Array<{ name: string, mechanism: string, constraint: string | null }> | null
	listsRefreshedAt: number | null
	lastUpgradeAt: number | null
	automaticUpdates: { state: 'enabled' | 'disabled' | 'unknown', mechanism: string | null, scope: 'security' | 'all' | 'download' | 'unknown' }
	rebootReasons: OsRebootReason[] | null
	restartServices: Array<{ unit: string, since: number | null, stack: boolean }> | null
	livePatches: string[] | null
	failedUnits: string[] | null
	timeSynchronized: boolean | null
	timeSource: string | null
	selinux: 'enforcing' | 'permissive' | 'disabled' | null
	apparmor: 'enabled' | 'disabled' | null
	upgradeCommands: { all: string | null, security: string | null }
	helper: { state: OsHelperState, path: string, generatedAt: number | null, version: string | null, outdated: boolean, errors: string[] }
	bootTime: number | null
	diagnostics: string[]
}

export interface OsThresholds {
	securityWarning: number
	securityError: number
	rebootWarning: number
	rebootError: number
	restartWarning: number
	eolWarning: number
	listsWarning: number
	listsError: number
}

/** Payload of /os */
export interface OsPayload {
	/** Server clock, so ages never depend on the browser's */
	now: number
	status: OsStatus | null
	verdict: { level: OsLevel, concerns: OsConcern[] } | null
	/** Packages changed after the status was collected */
	outdated: boolean
	php: { sampledAt: number, sapi: string, outdatedLibraries: string[], service: string | null, confinement: string | null } | null
	thresholds: OsThresholds
	notifications: boolean
	/** "Check now" queues a check for system cron; running while one is queued or collecting */
	refresh: { systemCron: boolean, running: boolean }
	helper: { bundledVersion: string, scriptPath: string, installPath: string, reportPath: string }
}
