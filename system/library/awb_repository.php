<?php
namespace Opencart\System\Library\Extension\Bookurier;

class AwbRepository {
	private object $db;

	public function __construct(object $db) {
		$this->db = $db;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getByOrderId(int $order_id): array {
		$query = $this->db->query("SELECT * FROM `" . Settings::awbTable(DB_PREFIX) . "` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1");

		return $query->row ?: [];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function save(array $data): int {
		$record = [
			'bookurier_awb_id' => 0,
			'order_id'         => 0,
			'courier_code'     => '',
			'awb_code'         => '',
			'locker_id'        => '',
			'provider_status'  => '',
			'panel_status'     => '',
			'error_message'    => null,
			'request_payload'  => null,
			'response_payload' => null
		];

		if (!empty($data['order_id'])) {
			$current = $this->getByOrderId((int)$data['order_id']);

			if ($current) {
				$record = $current + $record;
			}
		}

		foreach ($data as $key => $value) {
			$record[$key] = $value;
		}

		if (empty($record['order_id'])) {
			throw new \InvalidArgumentException('AWB record requires order_id.');
		}

		if (!empty($record['bookurier_awb_id'])) {
			$this->db->query("UPDATE `" . Settings::awbTable(DB_PREFIX) . "` SET "
				. "`courier_code` = '" . $this->db->escape((string)$record['courier_code']) . "', "
				. "`awb_code` = '" . $this->db->escape((string)$record['awb_code']) . "', "
				. "`locker_id` = '" . $this->db->escape((string)$record['locker_id']) . "', "
				. "`provider_status` = '" . $this->db->escape((string)$record['provider_status']) . "', "
				. "`panel_status` = '" . $this->db->escape((string)$record['panel_status']) . "', "
				. "`error_message` = " . $this->toSqlString($record['error_message']) . ", "
				. "`request_payload` = " . $this->toSqlString($record['request_payload']) . ", "
				. "`response_payload` = " . $this->toSqlString($record['response_payload']) . ", "
				. "`date_modified` = NOW() "
				. "WHERE `bookurier_awb_id` = '" . (int)$record['bookurier_awb_id'] . "'");

			return (int)$record['bookurier_awb_id'];
		}

		$this->db->query("INSERT INTO `" . Settings::awbTable(DB_PREFIX) . "` SET "
			. "`order_id` = '" . (int)$record['order_id'] . "', "
			. "`courier_code` = '" . $this->db->escape((string)$record['courier_code']) . "', "
			. "`awb_code` = '" . $this->db->escape((string)$record['awb_code']) . "', "
			. "`locker_id` = '" . $this->db->escape((string)$record['locker_id']) . "', "
			. "`provider_status` = '" . $this->db->escape((string)$record['provider_status']) . "', "
			. "`panel_status` = '" . $this->db->escape((string)$record['panel_status']) . "', "
			. "`error_message` = " . $this->toSqlString($record['error_message']) . ", "
			. "`request_payload` = " . $this->toSqlString($record['request_payload']) . ", "
			. "`response_payload` = " . $this->toSqlString($record['response_payload']) . ", "
			. "`date_added` = NOW(), "
			. "`date_modified` = NOW()");

		return $this->db->getLastId();
	}

	/**
	 * @param mixed $value
	 */
	private function toSqlString($value): string {
		if ($value === null) {
			return 'NULL';
		}

		return "'" . $this->db->escape((string)$value) . "'";
	}
}
