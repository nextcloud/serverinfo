<?php
/**
 * @author Frank Karlitschek <frank@nextcloud.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\ServerInfo\OperatingSystems;

/**
 * Class FreeBSD
 *
 * @package OCA\ServerInfo\OperatingSystems
 */
class FreeBSD {

        /**
         * @return bool
         */
        public function supported(): bool {
                return true;
        }

        /**
         * Get memory will return a list key => value where all values are in bytes.
         * [MemTotal => 0, MemFree => 0, MemAvailable => 0, SwapTotal => 0, SwapFree => 0].
         *
         * @return array
         */
        public function getMemory(): array {
                $data = ['MemTotal' => -1, 'MemFree' => -1, 'MemAvailable' => -1, 'SwapTotal' => -1, 'SwapFree' => -1];

		$swapinfo = shell_exec('/usr/sbin/swapinfo');

                $line = preg_split("/[\s]+/", $swapinfo);
                if (count($line) > 3) {
                        $data['SwapTotal'] = (int)$line[3];
                        $data['SwapFree'] = $data['SwapTotal'] - (int)$line[2];
                }

                $memTotal = shell_exec('/sbin/sysctl -n hw.physmem');
                $data['MemTotal'] = (int)$memTotal;

                $pagesize = shell_exec('/sbin/sysctl -n hw.pagesize');
                $inactiveMem = shell_exec('/sbin/sysctl -n vm.stats.vm.v_inactive_count');
                $cachedMem = shell_exec('/sbin/sysctl -n vm.stats.vm.v_cache_count');
                $freeMem = shell_exec('/sbin/sysctl -n vm.stats.vm.v_free_count');

                $data['MemAvailable'] = (int)$pagesize * ((int)$inactiveMem + (int)$cachedMem + (int)$freeMem);
		
                return $data;
        }

        /**
         * Get name of the processor
         *
         * @return string
         */
        public function getCPUName(): string {
                $data = shell_exec('/sbin/sysctl -n hw.model');
                return $data;
        }

        /**
         * @return string
         */
        public function getTime() {
                $uptime = shell_exec('date');
                return $uptime;
        }

        /**
         * Get the total number of seconds the system has been up or -1 on failure.
         *
         * @return int
         */
        public function getUptime(): int {
                $uptime = shell_exec('/sbin/sysctl -n kern.boottime | tr -d \',\' | cut -d \' \' -f4');
		$time = shell_exec('date +%s');
		$uptimeInSeconds = (int)$uptime - (int)$time;
                
                return $uptimeInSeconds;
        }

        /**
         * @return string
         */
        public function getTimeServers() {
                $servers = shell_exec('cat /etc/ntp.conf 2>/dev/null |grep  \'^pool\' | cut -f 2 -d " "');
                $servers .= ' ' . shell_exec('cat /etc/systemd/timesyncd.conf 2>/dev/null |grep  \'^NTP=\' | cut -f 2 -d " "');
                return $servers;
        }

        /**
         * @return string
         */
        public function getNetworkInfo() {
                $result = [];
                $result['hostname'] = \gethostname();
                $dns = shell_exec('cat /etc/resolv.conf |grep -i \'^nameserver\'|head -n1|cut -d \' \' -f2');
                $result['dns'] = $dns;
                $gw = shell_exec('netstat -rn | grep default | cut -d \' \' -f13');
                $result['gateway'] = $gw;
                return $result;
        }

        /**
         * @return string
         */
        public function getNetworkInterfaces() {
                $interfaces = glob('/sys/class/net/*');
                $result = [];

                foreach ($interfaces as $interface) {
                        $iface              = [];
                        $iface['interface'] = basename($interface);
                        $iface['mac']       = shell_exec('ip addr show dev ' . $iface['interface'] . ' | grep "link/ether " | cut -d \' \' -f 6  | cut -f 1 -d \'/\'');
                        $iface['ipv4']      = shell_exec('ip addr show dev ' . $iface['interface'] . ' | grep "inet " | cut -d \' \' -f 6  | cut -f 1 -d \'/\'');
                        $iface['ipv6']      = shell_exec('ip -o -6 addr show ' . $iface['interface'] . ' | sed -e \'s/^.*inet6 \([^ ]\+\).*/\1/\'');
                        if ($iface['interface'] !== 'lo') {
                                $iface['status'] = shell_exec('cat /sys/class/net/' . $iface['interface'] . '/operstate');
                                $iface['speed']  = shell_exec('cat /sys/class/net/' . $iface['interface'] . '/speed');
                                if ($iface['speed'] !== '') {
                                        $iface['speed'] = $iface['speed'] . 'Mbps';
                                } else {
                                        $iface['speed'] = 'unknown';
                                }

                                $duplex = shell_exec('cat /sys/class/net/' . $iface['interface'] . '/duplex');
                                if ($duplex !== '') {
                                        $iface['duplex'] = 'Duplex: ' . $duplex;
                                } else {
                                        $iface['duplex'] = '';
                                }
                        } else {
                                $iface['status'] = 'up';
                                $iface['speed']  = 'unknown';
                                $iface['duplex'] = '';
                        }
                        $result[] = $iface;
                }

                return $result;
        }

        /**
         * Get diskInfo will return a list of disks. Used and Available in bytes.
         *
         * [
         *      [device => /dev/mapper/homestead--vg-root, fs => ext4, used => 6205468, available => 47321220, percent => 12%, mount => /]
         * ]
         *
         * @return array
         */
        public function getDiskInfo(): array {
                $data = [];

                try {
                        $disks = $this->executeCommand('df -TP');
                } catch (\RuntimeException $e) {
                        return $data;
                }

                $matches = [];
                $pattern = '/^(?<Filesystem>[\w\/-]+)\s*(?<Type>\w+)\s*(?<Blocks>\d+)\s*(?<Used>\d+)\s*(?<Available>\d+)\s*(?<Capacity>\d+%)\s*(?<Mounted>[\w\/-]+)$/m';

                $result = preg_match_all($pattern, $disks, $matches);
                if ($result === 0 || $result === false) {
                        return $data;
                }

                foreach ($matches['Filesystem'] as $i => $filesystem) {
                        if (in_array($matches['Type'][$i], ['tmpfs', 'devtmpfs'], false)) {
                                continue;
                        }

                        $data[] = [
                                'device' => $filesystem,
                                'fs' => $matches['Type'][$i],
                                'used' => (int)$matches['Used'][$i] * 1024,
                                'available' => (int)$matches['Available'][$i] * 1024,
                                'percent' => $matches['Capacity'][$i],
                                'mount' => $matches['Mounted'][$i],
                        ];
                }

                return $data;
        }

        protected function readContent(string $filename): string {
                $data = @file_get_contents($filename);
                if ($data === false || $data === '') {
                        throw new \RuntimeException('Unable to read: "' . $filename . '"');
                }
                return $data;
        }

        protected function executeCommand(string $command): string {
                $output = @shell_exec(escapeshellcmd($command));
                if ($output === null || $output === '') {
                        throw new \RuntimeException('No output for command: "' . $command . '"');
                }
                return $output;
        }
}
