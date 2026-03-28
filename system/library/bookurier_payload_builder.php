<?php
namespace Opencart\System\Library\Extension\Bookurier;

class BookurierPayloadBuilder {
	private object $config;
	private BookurierOrderRepository $order_repository;

	public function __construct(object $registry, BookurierOrderRepository $order_repository) {
		$this->config = $registry->get('config');
		$this->order_repository = $order_repository;
	}

	/**
	 * @param array<string, mixed> $order
	 *
	 * @return array<string, string>
	 */
	public function build(array $order): array {
		$pickup_point = (int)$this->config->get('module_bookurier_bookurier_pickup_point');

		if ($pickup_point <= 0) {
			throw new \RuntimeException('Bookurier default pickup point is missing.');
		}

		$service = (int)$this->config->get('module_bookurier_bookurier_service');

		if ($service <= 0) {
			$service = 9;
		}

		$phone = $this->resolvePhone($order);

		if ($phone === '') {
			throw new \RuntimeException('Recipient phone is missing.');
		}

		$city = trim((string)($order['shipping_city'] ?? ''));
		$street = trim((string)($order['shipping_address_1'] ?? ''));

		if ($city === '') {
			throw new \RuntimeException('Delivery city is missing.');
		}

		if ($street === '') {
			throw new \RuntimeException('Delivery street is missing.');
		}

		$address = $this->resolveAddress($street, trim((string)($order['shipping_address_2'] ?? '')));
		$weight = $this->order_repository->calculateOrderWeightKg($order['products'] ?? []);
		$order_id = (int)($order['order_id'] ?? 0);

		return [
			'pickup_point'  => (string)$pickup_point,
			'unq'           => 'OC-' . $order_id . '-' . date('YmdHis'),
			'recv'          => $this->resolveRecipientName($order),
			'phone'         => $phone,
			'email'         => trim((string)($order['email'] ?? '')),
			'country'       => trim((string)($order['shipping_country'] ?? 'Romania')) ?: 'Romania',
			'city'          => $city,
			'zip'           => trim((string)($order['shipping_postcode'] ?? '')),
			'district'      => $this->resolveDistrict($order),
			'street'        => $address['street'],
			'no'            => $address['number'],
			'service'       => (string)$service,
			'packs'         => '1',
			'weight'        => number_format($weight, 2, '.', ''),
			'rbs_val'       => number_format($this->resolveCodValue($order), 2, '.', ''),
			'insurance_val' => '0',
			'ret_doc'       => '0',
			'weekend'       => '0',
			'unpack'        => '0',
			'exchange_pack' => '0',
			'confirmation'  => '0',
			'notes'         => 'Order #' . $order_id,
			'ref1'          => 'OC-' . $order_id,
			'ref2'          => (string)$order_id
		];
	}

	/**
	 * @param array<string, mixed> $order
	 */
	private function resolveRecipientName(array $order): string {
		$name = trim((string)($order['shipping_firstname'] ?? '') . ' ' . (string)($order['shipping_lastname'] ?? ''));

		if ($name === '') {
			$name = trim((string)($order['firstname'] ?? '') . ' ' . (string)($order['lastname'] ?? ''));
		}

		return $name !== '' ? $name : 'Client';
	}

	/**
	 * @param array<string, mixed> $order
	 */
	private function resolvePhone(array $order): string {
		$phone = preg_replace('/[^0-9+]/', '', trim((string)($order['telephone'] ?? '')));

		return is_string($phone) ? $phone : '';
	}

	/**
	 * @param array<string, mixed> $order
	 */
	private function resolveDistrict(array $order): string {
		$district = trim((string)($order['shipping_zone'] ?? ''));

		if ($district === '') {
			$district = trim((string)($order['shipping_city'] ?? ''));
		}

		return $district;
	}

	/**
	 * @param array<string, mixed> $order
	 */
	private function resolveCodValue(array $order): float {
		$payment_method = strtolower(json_encode($order['payment_method'] ?? []) ?: '');
		$payment_raw = strtolower((string)($order['payment_method_raw'] ?? ''));
		$haystack = $payment_method . ' ' . $payment_raw;

		$needles = [
			'cod',
			'cash on delivery',
			'cash_on_delivery',
			'cashondelivery',
			'ramburs'
		];

		foreach ($needles as $needle) {
			if (strpos($haystack, $needle) !== false) {
				return (float)($order['total'] ?? 0);
			}
		}

		return 0.0;
	}

	/**
	 * @return array{street:string, number:string}
	 */
	private function resolveAddress(string $address_1, string $address_2): array {
		$street = $address_1;
		$number = $address_2;

		if ($number === '' && preg_match('/^(.*?)[,\\s]+([0-9]+[A-Za-z0-9\\/-]*)$/u', $address_1, $matches)) {
			$street = trim($matches[1]);
			$number = trim($matches[2]);
		}

		return [
			'street' => $street,
			'number' => $number
		];
	}
}
