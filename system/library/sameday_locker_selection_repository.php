<?php
namespace Opencart\System\Library\Extension\Bookurier;

class SamedayLockerSelectionRepository {
	private object $db;

	public function __construct(object $db) {
		$this->db = $db;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getBySessionId(string $session_id): array {
		$session_id = trim($session_id);

		if ($session_id === '') {
			return [];
		}

		$query = $this->db->query("SELECT * FROM `" . Settings::lockerSelectionTable(DB_PREFIX) . "` "
			. "WHERE `session_id` = '" . $this->db->escape($session_id) . "' LIMIT 1");

		return $query->num_rows ? $query->row : [];
	}

	public function getLockerIdBySessionId(string $session_id): string {
		return trim((string)($this->getBySessionId($session_id)['locker_id'] ?? ''));
	}

	public function saveForSession(string $session_id, string $quote_code, string $locker_id): void {
		$session_id = trim($session_id);
		$quote_code = trim($quote_code);
		$locker_id = trim($locker_id);

		if ($session_id === '' || $quote_code === '' || $locker_id === '') {
			throw new \RuntimeException('Session, quote code, and locker ID are required.');
		}

		$now = date('Y-m-d H:i:s');
		$existing = $this->getBySessionId($session_id);

		if ($existing) {
			$this->db->query("UPDATE `" . Settings::lockerSelectionTable(DB_PREFIX) . "` SET "
				. "`quote_code` = '" . $this->db->escape($quote_code) . "', "
				. "`locker_id` = '" . $this->db->escape($locker_id) . "', "
				. "`date_modified` = '" . $this->db->escape($now) . "' "
				. "WHERE `bookurier_sameday_locker_selection_id` = '" . (int)$existing['bookurier_sameday_locker_selection_id'] . "'");

			return;
		}

		$this->db->query("INSERT INTO `" . Settings::lockerSelectionTable(DB_PREFIX) . "` SET "
			. "`session_id` = '" . $this->db->escape($session_id) . "', "
			. "`order_id` = '0', "
			. "`quote_code` = '" . $this->db->escape($quote_code) . "', "
			. "`locker_id` = '" . $this->db->escape($locker_id) . "', "
			. "`date_added` = '" . $this->db->escape($now) . "', "
			. "`date_modified` = '" . $this->db->escape($now) . "'");
	}

	public function bindSessionToOrder(string $session_id, int $order_id): void {
		$session_id = trim($session_id);

		if ($session_id === '' || $order_id <= 0) {
			return;
		}

		$this->db->query("UPDATE `" . Settings::lockerSelectionTable(DB_PREFIX) . "` SET "
			. "`order_id` = '" . (int)$order_id . "', "
			. "`date_modified` = '" . $this->db->escape(date('Y-m-d H:i:s')) . "' "
			. "WHERE `session_id` = '" . $this->db->escape($session_id) . "'");
	}

	public function getLockerIdByOrderId(int $order_id): string {
		if ($order_id <= 0) {
			return '';
		}

		$query = $this->db->query("SELECT `locker_id` FROM `" . Settings::lockerSelectionTable(DB_PREFIX) . "` "
			. "WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1");

		return trim((string)($query->row['locker_id'] ?? ''));
	}
}
