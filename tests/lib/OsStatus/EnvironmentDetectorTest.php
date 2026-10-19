<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\Environment;
use OCA\ServerInfo\OsStatus\EnvironmentDetector;

class EnvironmentDetectorTest extends \Test\TestCase {
	private FakeHost $host;

	protected function setUp(): void {
		parent::setUp();
		$this->host = new FakeHost();
	}

	private function detect(): Environment {
		return (new EnvironmentDetector($this->host))->detect();
	}

	public function testVirtualMachine(): void {
		$this->host->withCommand('systemd-detect-virt', ['--vm'], "vmware\n")
			->withCommand('systemd-detect-virt', ['--container'], "none\n", 1);

		$environment = $this->detect();

		$this->assertSame(Environment::KIND_HOST, $environment->kind);
		$this->assertSame('vmware', $environment->virtualization);
		$this->assertTrue($environment->managesPackages());
		$this->assertTrue($environment->ownsKernel());
	}

	public function testBareMetal(): void {
		$this->host->withCommand('systemd-detect-virt', ['--vm'], "none\n", 1);

		$this->assertNull($this->detect()->virtualization);
	}

	public function testDmiFallback(): void {
		$this->host->withFile('/sys/class/dmi/id/sys_vendor', "QEMU\n");

		$this->assertSame('kvm', $this->detect()->virtualization);
	}

	public function testDocker(): void {
		$this->host->files['/.dockerenv'] = '';

		$environment = $this->detect();

		$this->assertSame(Environment::KIND_APPLICATION_CONTAINER, $environment->kind);
		$this->assertSame('docker', $environment->container);
		$this->assertFalse($environment->managesPackages());
	}

	public function testLxcIsManagedLikeAMachineButNotItsKernel(): void {
		$this->host->withFile('/run/systemd/container', "lxc\n");

		$environment = $this->detect();

		$this->assertSame(Environment::KIND_SYSTEM_CONTAINER, $environment->kind);
		$this->assertTrue($environment->managesPackages());
		$this->assertFalse($environment->ownsKernel());
	}

	public function testKubernetesFromTheEnvironment(): void {
		$this->host->env['KUBERNETES_SERVICE_HOST'] = '10.0.0.1';

		$this->assertSame('kubernetes', $this->detect()->container);
	}

	public function testPodmanFromCgroups(): void {
		$this->host->withFile('/proc/1/cgroup', "0::/machine.slice/libpod-0123abcd.scope\n");

		$this->assertSame('podman', $this->detect()->container);
	}

	public function testSnap(): void {
		$this->host->env['SNAP'] = '/snap/nextcloud/1';
		$this->host->env['SNAP_NAME'] = 'nextcloud';

		$this->assertSame(Environment::KIND_SNAP, $this->detect()->kind);
	}

	public function testWslIsAHostWithoutItsOwnKernel(): void {
		$this->host->release = '5.15.153.1-microsoft-standard-WSL2';

		$environment = $this->detect();

		$this->assertSame(Environment::KIND_HOST, $environment->kind);
		$this->assertFalse($environment->ownsKernel());
	}

	public function testUnknownContainerNamesAreNotEchoedVerbatim(): void {
		$this->host->withFile('/run/systemd/container', "<script>alert(1)</script>\n");

		$this->assertSame('container-other', $this->detect()->container);
	}
}
