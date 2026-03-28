<?php
namespace Opencart\System\Library\Extension\Bookurier;

class SamedayPayloadBuilder {
	private object $config;
	private BookurierOrderRepository $order_repository;

	public function __construct(object $registry, BookurierOrderRepository $order_repository) {
		$this->config = $registry->get('config');
		$this->order_repository = $order_repository;
	}

	/**
	 * @param array<string, mixed> $order
	 * @param array<string, mixed> $locker
	 *
	 * @return array<string, mixed>
	 */
	public function build(array $order, array $locker, int $service_id): array {
		$pickup_point = (int)$this->config->get('module_bookurier_sameday_pickup_point');

		if ($pickup_point <= 0) {
			throw new \RuntimeException('SameDay pickup point is missing.');
		}

		$phone = $this->resolvePhone($order);

		if ($phone === '') {
			throw new \RuntimeException('Recipient phone is missing.');
		}

		$locker_id = trim((string)($locker['locker_id'] ?? ''));

		if ($locker_id === '') {
			throw new \RuntimeException('Selected locker is missing.');
		}

		$package_type = (int)$this->config->get('module_bookurier_sameday_package_type');

		if (!in_array($package_type, [0, 1, 2], true)) {
			$package_type = 0;
		}

		$order_id = (int)($order['order_id'] ?? 0);
		$weight = $this->order_repository->calculateOrderWeightKg($order['products'] ?? []);

		return [
			'pickupPoint'            => $pickup_point,
			'packageType'            => $package_type,
			'packageNumber'          => 1,
			'packageWeight'          => $weight,
			'service'                => $service_id,
			'awbPayment'             => 1,
			'cashOnDelivery'         => $this->resolveCodValue($order),
			'cashOnDeliveryReturns'  => 0.0,
			'insuredValue'           => 0.0,
			'thirdPartyPickup'       => 0,
			'lockerLastMile'         => (int)$locker_id,
			'awbRecipient'           => [
				'name'         => $this->resolveRecipientName($order),
				'phoneNumber'  => $phone,
				'personType'   => 0,
				'postalCode'   => trim((string)($locker['postal_code'] ?? '')),
				'cityString'   => trim((string)($locker['city'] ?? '')),
				'countyString' => trim((string)($locker['county'] ?? '')),
				'address'      => trim((string)($locker['address'] ?? '')),
				'email'        => trim((string)($order['email'] ?? ''))
			],
			'observation'            => 'Order #' . $order_id,
			'clientInternalReference'=> 'OC-' . $order_id . '-' . date('YmdHis'),
			'parcels'                => [
				['weight' => $weight]
			]
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
	private function resolveCodValue(array $order): float {
		$payment_method = strtolower(json_encode($order['payment_method'] ?? []) ?: '');
		$payment_raw = strtolower((string)($order['payment_method_raw'] ?? ''));
		$haystack = $payment_method . ' ' . $payment_raw;

		foreach (['cod', 'cash on delivery', 'cash_on_delivery', 'cashondelivery', 'ramburs'] as $needle) {
			if (strpos($haystack, $needle) !== false) {
				return (float)($order['total'] ?? 0);
			}
		}

		return 0.0;
	}
}
