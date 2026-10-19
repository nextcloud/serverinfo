<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus;

/**
 * The distribution, as /etc/os-release describes it.
 *
 * @see https://www.freedesktop.org/software/systemd/man/latest/os-release.html
 */
final class OsRelease {
	/**
	 * @param list<string> $idLike
	 */
	public function __construct(
		public readonly string $id,
		public readonly array $idLike,
		public readonly string $name,
		public readonly string $prettyName,
		public readonly ?string $versionId,
		public readonly ?string $codename,
		public readonly ?string $variantId = null,
	) {
	}

	public static function parse(string $content): ?self {
		$values = [];
		foreach (preg_split('/\R/', $content) ?: [] as $line) {
			if (preg_match('/^([A-Z0-9_]+)=(.*)$/', trim($line), $matches) !== 1) {
				continue;
			}
			$values[$matches[1]] = self::unquote($matches[2]);
		}

		$id = strtolower($values['ID'] ?? '');
		if (preg_match('/^[a-z0-9._-]+$/', $id) !== 1) {
			return null;
		}

		$idLike = array_values(array_filter(
			preg_split('/\s+/', strtolower($values['ID_LIKE'] ?? '')) ?: [],
			static fn (string $like): bool => preg_match('/^[a-z0-9._-]+$/', $like) === 1,
		));

		$name = $values['NAME'] ?? $id;
		$versionId = ($values['VERSION_ID'] ?? '') !== '' ? $values['VERSION_ID'] : null;
		$prettyName = $values['PRETTY_NAME'] ?? trim($name . ' ' . ($values['VERSION'] ?? ''));
		$codename = ($values['VERSION_CODENAME'] ?? '') !== '' ? $values['VERSION_CODENAME'] : null;
		$variantId = ($values['VARIANT_ID'] ?? '') !== '' ? strtolower($values['VARIANT_ID']) : null;

		return new self($id, $idLike, $name, $prettyName, $versionId, $codename, $variantId);
	}

	/**
	 * Whether this is the given distribution or derived from it.
	 */
	public function isLike(string $id): bool {
		return $this->id === $id || in_array($id, $this->idLike, true);
	}

	/**
	 * @return array{id: string, idLike: list<string>, name: string, prettyName: string, versionId: ?string, codename: ?string, variantId: ?string}
	 */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'idLike' => $this->idLike,
			'name' => $this->name,
			'prettyName' => $this->prettyName,
			'versionId' => $this->versionId,
			'codename' => $this->codename,
			'variantId' => $this->variantId,
		];
	}

	public static function fromArray(mixed $data): ?self {
		if (!is_array($data) || !is_string($data['id'] ?? null)) {
			return null;
		}

		return new self(
			$data['id'],
			array_values(array_filter((array)($data['idLike'] ?? []), 'is_string')),
			(string)($data['name'] ?? $data['id']),
			(string)($data['prettyName'] ?? $data['id']),
			Values::string($data['versionId'] ?? null),
			Values::string($data['codename'] ?? null),
			Values::string($data['variantId'] ?? null),
		);
	}

	/**
	 * Values may be double- or single-quoted with shell-style escapes.
	 */
	private static function unquote(string $value): string {
		$value = trim($value);
		if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
			$inner = substr($value, 1, -1);

			return $value[0] === '"' ? (string)preg_replace('/\\\\([\\\\"$`])/', '$1', $inner) : $inner;
		}

		return $value;
	}
}
