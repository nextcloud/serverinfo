/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { ref } from 'vue'

export interface StaticData {
	hostname: string
	osname: string
	cpu: { name: string, threads: number }
	diskinfo: Array<{ device: string, fs: string, used: number, available: number, percent: string, mount: string }>
	networkinfo: { hostname: string, gateway: string, dns: string }
	networkinterfaces: Array<{
		name: string
		up: boolean
		ipv4: string[]
		ipv6: string[]
		mac: string
		speed: string
		duplex: string
		loopback: boolean
	}>
	ocs: string
	storage: { num_files: number, num_storages: number, num_users: number }
	shares: {
		num_shares: number
		num_shares_user: number
		num_shares_groups: number
		num_shares_link: number
		num_shares_mail: number
		num_fed_shares_sent: number
		num_fed_shares_received: number
		num_shares_room: number
	}
	php: {
		version: string
		memory_limit: number
		max_execution_time: number
		upload_max_filesize: number
		opcache_revalidate_freq: number
		extensions: string[] | null
	}
	fpm: Record<string, string | number> | false
	database: { type: string, version: string, size: number }
	activeUsers: { last1hour: number, last24hours: number, last7days: number, last1month: number }
	freeSpace: number | null
	memTotal: number
	phpinfo: boolean
	phpinfoUrl: string
}

export function useStaticData() {
	const data = ref<StaticData | null>(null)
	const loading = ref(true)
	const error = ref(false)

	async function load() {
		try {
			const response = await axios.get(generateUrl('/apps/serverinfo/data'))
			data.value = response.data
		} catch {
			error.value = true
		} finally {
			loading.value = false
		}
	}

	load()

	return { data, loading, error }
}
