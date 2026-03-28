<?php
namespace Opencart\System\Library\Extension\Bookurier;

class SamedayLockerRepository {
	private object $db;

	public function __construct(object $db) {
		$this->db = $db;
	}

	public function countActive(): int {
		$query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . Settings::lockerTable(DB_PREFIX) . "` WHERE `is_active` = '1'");

		return (int)($query->row['total'] ?? 0);
	}

	/**
	 * @param array<int, array<string, mixed>> $lockers
	 */
	public function upsertMany(array $lockers): int {
		$now = date('Y-m-d H:i:s');
		$active_locker_ids = [];

		foreach ($lockers as $locker) {
			$locker_id = trim((string)($locker['lockerId'] ?? ''));

			if ($locker_id === '') {
				continue;
			}

			$active_locker_ids[$locker_id] = $locker_id;
			$raw_payload = json_encode($locker, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$record = [
				'locker_id'   => $locker_id,
				'name'        => trim((string)($locker['name'] ?? '')),
				'city'        => trim((string)($locker['city'] ?? '')),
				'county'      => trim((string)($locker['county'] ?? '')),
				'address'     => trim((string)($locker['address'] ?? '')),
				'postal_code' => trim((string)($locker['postalCode'] ?? '')),
				'latitude'    => $this->normalizeCoordinate($locker['lat'] ?? null),
				'longitude'   => $this->normalizeCoordinate($locker['long'] ?? ($locker['lng'] ?? null)),
				'box_count'   => $this->resolveBoxCount($locker),
				'is_active'   => 1,
				'raw_payload' => $raw_payload === false ? '{}' : $raw_payload
			];

			$existing = $this->db->query("SELECT `bookurier_sameday_locker_id` FROM `" . Settings::lockerTable(DB_PREFIX) . "` WHERE `locker_id` = '" . $this->db->escape($locker_id) . "' LIMIT 1");

			if ($existing->num_rows) {
				$this->db->query("UPDATE `" . Settings::lockerTable(DB_PREFIX) . "` SET "
					. "`name` = '" . $this->db->escape($record['name']) . "', "
					. "`city` = '" . $this->db->escape($record['city']) . "', "
					. "`county` = '" . $this->db->escape($record['county']) . "', "
					. "`address` = '" . $this->db->escape($record['address']) . "', "
					. "`postal_code` = '" . $this->db->escape($record['postal_code']) . "', "
					. "`latitude` = " . $this->sqlNullableDecimal($record['latitude']) . ", "
					. "`longitude` = " . $this->sqlNullableDecimal($record['longitude']) . ", "
					. "`box_count` = '" . (int)$record['box_count'] . "', "
					. "`is_active` = '" . (int)$record['is_active'] . "', "
					. "`raw_payload` = '" . $this->db->escape($record['raw_payload']) . "', "
					. "`date_modified` = '" . $this->db->escape($now) . "' "
					. "WHERE `bookurier_sameday_locker_id` = '" . (int)$existing->row['bookurier_sameday_locker_id'] . "'");
			} else {
				$this->db->query("INSERT INTO `" . Settings::lockerTable(DB_PREFIX) . "` SET "
					. "`locker_id` = '" . $this->db->escape($record['locker_id']) . "', "
					. "`name` = '" . $this->db->escape($record['name']) . "', "
					. "`city` = '" . $this->db->escape($record['city']) . "', "
					. "`county` = '" . $this->db->escape($record['county']) . "', "
					. "`address` = '" . $this->db->escape($record['address']) . "', "
					. "`postal_code` = '" . $this->db->escape($record['postal_code']) . "', "
					. "`latitude` = " . $this->sqlNullableDecimal($record['latitude']) . ", "
					. "`longitude` = " . $this->sqlNullableDecimal($record['longitude']) . ", "
					. "`box_count` = '" . (int)$record['box_count'] . "', "
					. "`is_active` = '" . (int)$record['is_active'] . "', "
					. "`raw_payload` = '" . $this->db->escape($record['raw_payload']) . "', "
					. "`date_added` = '" . $this->db->escape($now) . "', "
					. "`date_modified` = '" . $this->db->escape($now) . "'");
			}
		}

		if (!$active_locker_ids) {
			throw new \RuntimeException('No valid locker IDs were returned by SameDay.');
		}

		$escaped_ids = [];

		foreach ($active_locker_ids as $locker_id) {
			$escaped_ids[] = "'" . $this->db->escape($locker_id) . "'";
		}

		$this->db->query("UPDATE `" . Settings::lockerTable(DB_PREFIX) . "` SET "
			. "`is_active` = '0', "
			. "`date_modified` = '" . $this->db->escape($now) . "' "
			. "WHERE `locker_id` NOT IN (" . implode(', ', $escaped_ids) . ")");

		return count($active_locker_ids);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getActiveForCheckout(): array {
		$query = $this->db->query("SELECT `locker_id`, `name`, `city`, `county`, `address`, `postal_code` "
			. "FROM `" . Settings::lockerTable(DB_PREFIX) . "` "
			. "WHERE `is_active` = '1' "
			. "ORDER BY `city` ASC, `name` ASC");

		$lockers = [];

		foreach ($query->rows as $row) {
			$locker_id = trim((string)($row['locker_id'] ?? ''));

			if ($locker_id === '') {
				continue;
			}

			$label = $this->buildCheckoutLabel($row);

			$lockers[] = [
				'locker_id'   => $locker_id,
				'label'       => $label,
				'city'        => trim((string)($row['city'] ?? '')),
				'county'      => trim((string)($row['county'] ?? '')),
				'address'     => trim((string)($row['address'] ?? '')),
				'postal_code' => trim((string)($row['postal_code'] ?? '')),
				'search_text' => strtolower($label)
			];
		}

		return $lockers;
	}

	public function isActiveLockerId(string $locker_id): bool {
		$locker_id = trim($locker_id);

		if ($locker_id === '') {
			return false;
		}

		$query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . Settings::lockerTable(DB_PREFIX) . "` "
			. "WHERE `locker_id` = '" . $this->db->escape($locker_id) . "' AND `is_active` = '1'");

		return (int)($query->row['total'] ?? 0) > 0;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function findActiveLockerById(string $locker_id): ?array {
		$locker_id = trim($locker_id);

		if ($locker_id === '') {
			return null;
		}

		$query = $this->db->query("SELECT `locker_id`, `name`, `county`, `city`, `address`, `postal_code`, `box_count` "
			. "FROM `" . Settings::lockerTable(DB_PREFIX) . "` "
			. "WHERE `locker_id` = '" . $this->db->escape($locker_id) . "' AND `is_active` = '1' "
			. "LIMIT 1");

		return $query->num_rows ? $query->row : null;
	}

	/**
	 * @param array<string, mixed> $shipping_address
	 * @param array<int, array<string, mixed>> $lockers
	 */
	public function findBestLockerIdForAddress(array $shipping_address, array $lockers): string {
		if (!$lockers) {
			return '';
		}

		$city = $this->normalizeText((string)($shipping_address['city'] ?? ''));
		$postcode = $this->normalizeText((string)($shipping_address['postcode'] ?? ''));
		$street = $this->normalizeText(trim((string)($shipping_address['address_1'] ?? '') . ' ' . (string)($shipping_address['address_2'] ?? '')));
		$best_locker_id = '';
		$best_score = -1;

		foreach ($lockers as $locker) {
			$locker_id = trim((string)($locker['locker_id'] ?? ''));

			if ($locker_id === '') {
				continue;
			}

			$score = 0;
			$locker_city = $this->normalizeText((string)($locker['city'] ?? ''));
			$locker_county = $this->normalizeText((string)($locker['county'] ?? ''));
			$locker_address = $this->normalizeText((string)($locker['address'] ?? ''));
			$locker_postcode = $this->normalizeText((string)($locker['postal_code'] ?? ''));

			if ($city !== '' && $locker_city === $city) {
				$score += 100;
			}

			if ($postcode !== '' && $locker_postcode !== '' && $locker_postcode === $postcode) {
				$score += 60;
			}

			if ($street !== '' && $locker_address !== '' && strpos($locker_address, $street) !== false) {
				$score += 40;
			}

			if ($city !== '' && $locker_county !== '' && strpos($locker_county, $city) !== false) {
				$score += 5;
			}

			if ($score > $best_score) {
				$best_score = $score;
				$best_locker_id = $locker_id;
			}
		}

		return $best_score > 0 ? $best_locker_id : '';
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function buildCheckoutLabel(array $row): string {
		$name = trim((string)($row['name'] ?? ''));
		$city = trim((string)($row['city'] ?? ''));
		$county = trim((string)($row['county'] ?? ''));
		$postal_code = trim((string)($row['postal_code'] ?? ''));
		$address = trim((string)($row['address'] ?? ''));
		$label = $name !== '' ? $name : 'Locker #' . (string)($row['locker_id'] ?? '');
		$details = trim($city
			. ($county !== '' ? ', ' . $county : '')
			. ($postal_code !== '' ? ' ' . $postal_code : '')
			. ($address !== '' ? ' - ' . $address : ''), ' -');

		return $details !== '' ? $label . ' (' . $details . ')' : $label;
	}

	/**
	 * @param mixed $value
	 */
	private function normalizeCoordinate($value): ?float {
		if ($value === null || $value === '') {
			return null;
		}

		return round((float)$value, 7);
	}

	/**
	 * @param array<string, mixed> $locker
	 */
	private function resolveBoxCount(array $locker): int {
		if (isset($locker['boxesCount'])) {
			return (int)$locker['boxesCount'];
		}

		if (!empty($locker['availableBoxes']) && is_array($locker['availableBoxes'])) {
			return count($locker['availableBoxes']);
		}

		return 0;
	}

	private function sqlNullableDecimal(?float $value): string {
		return $value === null ? 'NULL' : "'" . $this->db->escape((string)$value) . "'";
	}

	private function normalizeText(string $value): string {
		return strtolower(trim($value));
	}
}
