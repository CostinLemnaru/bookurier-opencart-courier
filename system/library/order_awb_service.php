<?php
namespace Opencart\System\Library\Extension\Bookurier;

class OrderAwbService {
	private object $registry;
	private object $logger;
	private BookurierOrderRepository $order_repository;
	private BookurierAwbService $bookurier_service;
	private SamedayAwbService $sameday_service;

	public function __construct(object $registry) {
		$this->registry = $registry;
		$this->logger = Platform::createLog(Settings::LOG_FILE);
		$this->order_repository = new BookurierOrderRepository($registry);
		$this->bookurier_service = new BookurierAwbService($registry);
		$this->sameday_service = new SamedayAwbService($registry);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getOrderContext(int $order_id): array {
		$order = $this->order_repository->getOrder($order_id);

		if (!$order) {
			throw new \RuntimeException('Order not found.');
		}

		$courier_code = $this->resolveCourierCode($order);

		if ($courier_code === Settings::COURIER_BOOKURIER) {
			return $this->decorateContext($this->bookurier_service->getOrderContext($order_id), $courier_code);
		}

		if ($courier_code === Settings::COURIER_SAMEDAY_LOCKER) {
			return $this->decorateContext($this->sameday_service->getOrderContext($order_id), $courier_code);
		}

		return $this->decorateContext([
			'order_id'             => $order_id,
			'order_status_name'    => (string)($order['order_status_name'] ?? ''),
			'shipping_method_name' => $this->resolveShippingMethodName($order),
			'is_supported_order'   => false,
			'configuration_issues' => [],
			'is_configured'        => false,
			'has_tracking'         => false,
			'can_generate'         => false,
			'can_download'         => false,
			'can_refresh'          => false,
			'awb'                  => [
				'order_id'         => $order_id,
				'courier_code'     => '',
				'awb_code'         => '',
				'locker_id'        => '',
				'provider_status'  => '',
				'panel_status'     => '',
				'error_message'    => '',
				'request_payload'  => '',
				'response_payload' => '',
				'date_added'       => '',
				'date_modified'    => ''
			],
			'locker_id'            => '',
			'locker_label'         => ''
		], '');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function generateForOrder(int $order_id, string $trigger = 'manual', bool $allow_existing = false): array {
		return $this->resolveServiceByOrderId($order_id)->generateForOrder($order_id, $trigger, $allow_existing);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function refreshStatusForOrder(int $order_id): array {
		return $this->resolveServiceByOrderId($order_id)->refreshStatusForOrder($order_id);
	}

	/**
	 * @return array<string, string>
	 */
	public function downloadLabelForOrder(int $order_id): array {
		return $this->resolveServiceByOrderId($order_id)->downloadLabelForOrder($order_id);
	}

	public function log(string $level, string $message, array $context = []): void {
		$prefix = strtoupper($level);
		$payload = $context ? ' ' . $this->encodeJson($context) : '';

		$this->logger->write('[Courier][' . $prefix . '] ' . $message . $payload);
	}

	/**
	 * @param array<string, mixed> $context
	 *
	 * @return array<string, mixed>
	 */
	private function decorateContext(array $context, string $courier_code): array {
		$context['courier_code'] = $courier_code;
		$context['courier_label'] = $this->resolveCourierLabel($courier_code);
		$context['is_supported_order'] = !empty($context['is_supported_order']) || !empty($context['is_bookurier_order']);

		return $context;
	}

	private function resolveServiceByOrderId(int $order_id) {
		$order = $this->order_repository->getOrder($order_id);

		if (!$order) {
			throw new \RuntimeException('Order not found.');
		}

		$courier_code = $this->resolveCourierCode($order);

		if ($courier_code === Settings::COURIER_BOOKURIER) {
			return $this->bookurier_service;
		}

		if ($courier_code === Settings::COURIER_SAMEDAY_LOCKER) {
			return $this->sameday_service;
		}

		throw new \RuntimeException('Order shipping method is not supported by the courier integration.');
	}

	/**
	 * @param array<string, mixed> $order
	 */
	private function resolveCourierCode(array $order): string {
		if ($this->bookurier_service->isBookurierOrder($order)) {
			return Settings::COURIER_BOOKURIER;
		}

		if ($this->sameday_service->isSamedayOrder($order)) {
			return Settings::COURIER_SAMEDAY_LOCKER;
		}

		return '';
	}

	private function resolveCourierLabel(string $courier_code): string {
		switch ($courier_code) {
			case Settings::COURIER_BOOKURIER:
				return 'Bookurier';
			case Settings::COURIER_SAMEDAY_LOCKER:
				return 'SameDay Locker';
			default:
				return 'Unsupported';
		}
	}

	/**
	 * @param array<string, mixed> $order
	 */
	private function resolveShippingMethodName(array $order): string {
		$name = trim((string)($order['shipping_method']['name'] ?? ''));

		if ($name === '') {
			$name = trim((string)($order['shipping_method_raw'] ?? ''));
		}

		return $name;
	}

	/**
	 * @param mixed $data
	 */
	private function encodeJson($data): string {
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return $json === false ? '{}' : $json;
	}
}
