<?php
namespace Opencart\System\Library\Extension\Bookurier;

class BookurierOrderRepository {
	private object $db;
	private object $config;
	private object $weight;
	private ?int $kilogram_weight_class_id = null;
	private ?bool $has_master_id_column = null;

	public function __construct(object $registry) {
		$this->db = $registry->get('db');
		$this->config = $registry->get('config');
		$this->weight = Platform::createWeight($registry);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getOrder(int $order_id): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1");

		if (!$query->num_rows) {
			return [];
		}

		$order = $query->row;
		$order['custom_field'] = $this->decodeJsonArray((string)$order['custom_field']);
		$order['payment_custom_field'] = $this->decodeJsonArray((string)$order['payment_custom_field']);
		$order['shipping_custom_field'] = $this->decodeJsonArray((string)$order['shipping_custom_field']);
		$order['payment_method_raw'] = (string)$order['payment_method'];
		$order['shipping_method_raw'] = (string)$order['shipping_method'];
		$order['payment_method'] = $this->decodeJsonArray((string)$order['payment_method']);
		$order['shipping_method'] = $this->decodeJsonArray((string)$order['shipping_method']);
		$order['order_status_name'] = $this->getOrderStatusName((int)$order['order_status_id'], (int)$order['language_id']);
		$order['products'] = $this->getProducts($order_id);

		return $order;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getProducts(int $order_id): array {
		if ($this->hasMasterIdColumn()) {
			$query = $this->db->query("SELECT `op`.*, "
				. "COALESCE(`p`.`weight`, `mp`.`weight`, 0) AS `product_weight`, "
				. "COALESCE(`p`.`weight_class_id`, `mp`.`weight_class_id`, 0) AS `product_weight_class_id` "
				. "FROM `" . DB_PREFIX . "order_product` `op` "
				. "LEFT JOIN `" . DB_PREFIX . "product` `p` ON (`p`.`product_id` = `op`.`product_id`) "
				. "LEFT JOIN `" . DB_PREFIX . "product` `mp` ON (`mp`.`product_id` = `op`.`master_id`) "
				. "WHERE `op`.`order_id` = '" . (int)$order_id . "'");
		} else {
			$query = $this->db->query("SELECT `op`.*, "
				. "COALESCE(`p`.`weight`, 0) AS `product_weight`, "
				. "COALESCE(`p`.`weight_class_id`, 0) AS `product_weight_class_id` "
				. "FROM `" . DB_PREFIX . "order_product` `op` "
				. "LEFT JOIN `" . DB_PREFIX . "product` `p` ON (`p`.`product_id` = `op`.`product_id`) "
				. "WHERE `op`.`order_id` = '" . (int)$order_id . "'");
		}

		return $query->rows;
	}

	/**
	 * @param array<int, array<string, mixed>> $products
	 */
	public function calculateOrderWeightKg(array $products): float {
		$total = 0.0;
		$kg_weight_class_id = $this->getKilogramWeightClassId();

		foreach ($products as $product) {
			$weight = (float)($product['product_weight'] ?? 0);
			$quantity = (int)($product['quantity'] ?? 0);
			$weight_class_id = (int)($product['product_weight_class_id'] ?? 0);

			if ($weight <= 0 || $quantity <= 0) {
				continue;
			}

			$product_weight = $weight * $quantity;

			if ($kg_weight_class_id > 0 && $weight_class_id > 0) {
				$product_weight = (float)$this->weight->convert($product_weight, $weight_class_id, $kg_weight_class_id);
			}

			$total += $product_weight;
		}

		if ($total <= 0) {
			$total = 1.0;
		}

		return round($total, 2);
	}

	public function getOrderStatusName(int $order_status_id, int $language_id = 0): string {
		if ($order_status_id <= 0) {
			return '';
		}

		if ($language_id <= 0) {
			$language_id = (int)$this->config->get('config_language_id');
		}

		$query = $this->db->query("SELECT `name` FROM `" . DB_PREFIX . "order_status` WHERE `order_status_id` = '" . (int)$order_status_id . "' AND `language_id` = '" . (int)$language_id . "' LIMIT 1");

		return (string)($query->row['name'] ?? '');
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decodeJsonArray(string $value): array {
		$value = trim($value);

		if ($value === '') {
			return [];
		}

		$data = json_decode($value, true);

		return is_array($data) ? $data : [];
	}

	private function getKilogramWeightClassId(): int {
		if ($this->kilogram_weight_class_id !== null) {
			return $this->kilogram_weight_class_id;
		}

		$query = $this->db->query("SELECT `weight_class_id` FROM `" . DB_PREFIX . "weight_class_description` WHERE LOWER(`unit`) = 'kg' LIMIT 1");

		$this->kilogram_weight_class_id = (int)($query->row['weight_class_id'] ?? 0);

		if ($this->kilogram_weight_class_id <= 0) {
			$this->kilogram_weight_class_id = (int)$this->config->get('config_weight_class_id');
		}

		return $this->kilogram_weight_class_id;
	}

	private function hasMasterIdColumn(): bool {
		if ($this->has_master_id_column !== null) {
			return $this->has_master_id_column;
		}

		$query = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "order_product` LIKE 'master_id'");

		$this->has_master_id_column = (bool)$query->num_rows;

		return $this->has_master_id_column;
	}
}
