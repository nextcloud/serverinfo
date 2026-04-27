/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export interface CpuInfo {
	name: string
	threads: number
}

export interface MemoryInfo {
	/** Total memory in MB */
	total: number
	/** Free memory in MB */
	free: number
	/** Available memory in MB */
	available: number
	/** Total swap in MB */
	swapTotal: number
	/** Free swap in MB */
	swapFree: number
}

export interface DiskInfo {
	device: string
	fs: string
	mount: string
	/** Used size in MB */
	used: number
	/** Available size in MB */
	available: number
	/** Used percent as string, e.g. "42%" */
	percent: string
}

export interface NetworkInterfaceInfo {
	name: string
	up: boolean
	ipv4: string[]
	ipv6: string[]
	mac: string
	speed: string
	duplex: string
	loopback: boolean
}

export interface NetworkInfo {
	hostname: string
	gateway: string
	dns: string
}

export interface ThermalZoneInfo {
	zone: string
	type: string
	temp: number
}

export interface ActiveUsers {
	last5minutes: number
	last1hour: number
	last24hours: number
	last7days: number
	last1month: number
}

export interface StorageStats {
	num_users: number
	num_files: number
	num_storages: number
	num_storages_local: number
	num_storages_home: number
	num_storages_other: number
}

export interface ShareStats {
	num_shares: number
	num_shares_user: number
	num_shares_groups: number
	num_shares_link: number
	num_shares_mail: number
	num_shares_room: number
	num_shares_link_no_password: number
	num_fed_shares_sent: number
	num_fed_shares_received: number
}

export interface PhpInfo {
	version: string
	memory_limit: number
	max_execution_time: number
	upload_max_filesize: number
	opcache_revalidate_freq: number
	extensions: string[] | null
}

export interface FpmInfo {
	pool: string
	'process-manager': string
	'start-time': string | number
	'accepted-conn': number
	'total-processes': number
	'active-processes': number
	'idle-processes': number
	'listen-queue': number
	'slow-requests': number
	'max-listen-queue': number
	'max-active-processes': number
	'max-children-reached': number
}

export interface DatabaseInfo {
	type: string
	version: string
	size: number
}

export interface SystemInfo {
	cpuload: number[] | false
	cpunum: number
	mem_total: number
	mem_free: number
	swap_total: number
	swap_free: number
	freespace: number | null
}

export interface AppUpdate {
	id: string
	version: string
}

export interface AppsInfo {
	numInstalled: number
	numUpdatesAvailable: number
	appUpdates: AppUpdate[]
}

export interface CronStatus {
	mode: string
	lastRun: number
	secondsSince: number
	status: HealthStatus
}

export interface JobClassCount {
	class: string
	count: number
}

export interface JobQueueStats {
	total: number
	reserved: number
	stuck: number
	oldestLastRun: number
	topClasses: JobClassCount[]
}

export interface LogEntry {
	time: string
	level: number
	app: string
	message: string
}

export interface RecentErrors {
	entries: LogEntry[]
	available: boolean
	reason?: string
}

export interface LoginStats {
	bruteforceAttempts24h: number
	bruteforceAttempts1h: number
	bruteforceTotal: number
	topIps: { ip: string, count: number }[]
	available: boolean
}

export interface OPcacheStats {
	enabled: boolean
	hits: number
	misses: number
	hitRate: number
	memoryUsedMB: number
	memoryFreeMB: number
	cachedScripts: number
}

export interface APCuStats {
	enabled: boolean
	hits: number
	misses: number
	hitRate: number
	memoryUsedMB: number
	memoryFreeMB: number
}

export interface CachingInfo {
	opcache: OPcacheStats
	apcu: APCuStats
	redis: { configured: boolean, distributed: string, locking: string }
	memcache: { local: string, distributed: string, locking: string }
}

export interface SlowJob {
	class: string
	count: number
	avgSeconds: number
	maxSeconds: number
}

export interface TableInfo {
	name: string
	rows: number
	sizeBytes: number
}

export interface DbHealth {
	driver: string
	largestTables: TableInfo[]
	available: boolean
}

export interface ExternalMount {
	name: string
	backend: string
	scope: 'admin' | 'user'
}

export interface ExternalStorages {
	installed: boolean
	count: number
	mounts: ExternalMount[]
}

export interface AppStoreCheck {
	reachable: boolean
	statusCode: number
	latencyMs: number
	checkedAt: number
	cached: boolean
}

export interface TopUser {
	user: string
	sizeBytes: number
}

export interface ActivityInfo {
	installed: boolean
	last1h: number
	last24h: number
	last7d: number
	topActions: { action: string, count: number }[]
}

export interface ConnectionsInfo {
	last5min: number
	last1h: number
	totalTokens: number
	byType: Record<string, number>
}

export interface DiskGrowthSample {
	ts: number
	freeBytes: number
	files: number
}

export interface DiskGrowthInfo {
	samples: DiskGrowthSample[]
	daysUntilFull: number
	bytesPerDay: number
	filesPerDay: number
	freeBytes: number
	hasEnoughData: boolean
}

export interface FederationInfo {
	enabled: boolean
	sharesSent: number
	sharesReceived: number
	sharesSentToGroups: number
	trustedServers: number
	topPeers: { server: string, count: number }[]
}

export interface EolEntry {
	version: string
	major?: string
	eol: string | null
	daysUntilEol: number | null
	status: HealthStatus | 'unknown'
}

export interface EolInfo {
	php: EolEntry
	nextcloud: EolEntry
}

export interface OsUpdatesInfo {
	supported: boolean
	distro: string
	updatesAvailable: number
	securityUpdates: number
	rebootRequired: boolean
	rebootPackages: string[]
	summary: string
	source: string
}

export interface ServerInfoState {
	hostname: string
	osname: string
	cpu: CpuInfo
	memory: MemoryInfo
	disks: DiskInfo[]
	networkinfo: NetworkInfo
	interfaces: NetworkInterfaceInfo[]
	thermalzones: ThermalZoneInfo[]
	storage: StorageStats
	shares: ShareStats
	php: PhpInfo
	fpm: FpmInfo | false
	database: DatabaseInfo
	activeUsers: ActiveUsers
	system: SystemInfo
	apps: AppsInfo
	cron: CronStatus
	jobQueue: JobQueueStats
	recentErrors: RecentErrors
	logins: LoginStats
	caching: CachingInfo
	slowestJobs: SlowJob[]
	dbHealth: DbHealth
	externalStorages: ExternalStorages
	appStore: AppStoreCheck
	topUsers: TopUser[]
	activity: ActivityInfo
	connections: ConnectionsInfo
	diskGrowth: DiskGrowthInfo
	federation: FederationInfo
	eol: EolInfo
	osUpdates: OsUpdatesInfo
	phpinfoEnabled: boolean
	phpinfoUrl: string
	updateUrl: string
	appsAdminUrl: string
	backgroundJobsUrl: string
	overviewUrl: string
	logSettingsUrl: string
	serverSettingsUrl: string
	monitoringEndpoint: string
}

export interface LiveUpdate {
	system: SystemInfo
	thermalzones: ThermalZoneInfo[]
	/** System uptime in seconds, or -1 when unavailable. */
	uptime: number
}

export type HealthStatus = 'ok' | 'warning' | 'critical'
