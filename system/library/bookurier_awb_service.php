<?php
namespace Opencart\System\Library\Extension\Bookurier;

class BookurierAwbService {
	private object $config;
	private object $db;
	private object $logger;
	private AwbRepository $awb_repository;
	private BookurierOrderRepository $order_repository;
	private BookurierPayloadBuilder $payload_builder;

	public function __construct(object $registry) {
		$this->config = $registry->get('config');
		$this->db = $registry->get('db');
		$this->logger = Platform::createLog(Settings::LOG_FILE);
		$this->awb_repository = new AwbRepository($this->db);
		$this->order_repository = new BookurierOrderRepository($registry);
		$this->payload_builder = new BookurierPayloadBuilder($registry, $this->order_repository);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getOrderContext(int $order_id): array {
		$order = $this->order_repository->getOrder($order_id);

		if (!$order) {
			throw new \RuntimeException('Order not found.');
		}

		$awb = $this->normalizeAwbRecord($this->awb_repository->getByOrderId($order_id));
		$configuration_issues = $this->getConfigurationIssues();
		$is_bookurier_order = $this->isBookurierOrder($order);
		$has_awb = $awb['awb_code'] !== '';

		return [
			'order_id'              => $order_id,
			'order_status_name'     => (string)($order['order_status_name'] ?? ''),
			'shipping_method_name'  => $this->resolveShippingMethodName($order),
			'is_bookurier_order'    => $is_bookurier_order,
			'configuration_issues'  => $configuration_issues,
			'is_configured'         => empty($configuration_issues),
			'has_tracking'          => trim((string)$this->config->get('module_bookurier_bookurier_api_key')) !== '',
			'can_generate'          => $is_bookurier_order && !$has_awb && empty($configuration_issues),
			'can_download'          => $has_awb,
			'can_refresh'           => $has_awb && trim((string)$this->config->get('module_bookurier_bookurier_api_key')) !== '',
			'awb'                   => $awb
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function generateForOrder(int $order_id, string $trigger = 'manual', bool $allow_existing = false): array {
		$order = $this->order_repository->getOrder($order_id);

		if (!$order) {
			throw new \RuntimeException('Order not found.');
		}

		if (!$this->isBookurierOrder($order)) {
			throw new \RuntimeException('Order shipping method is not Bookurier.');
		}

		$existing = $this->normalizeAwbRecord($this->awb_repository->getByOrderId($order_id));

		if ($existing['awb_code'] !== '') {
			if ($allow_existing) {
				return $existing;
			}

			throw new \RuntimeException('AWB already exists for this order.');
		}

		$issues = $this->getConfigurationIssues();

		if ($issues) {
			throw new \RuntimeException(implode(' ', $issues));
		}

		$request_payload = '';
		$response_payload = '';

		try {
			$payload = $this->payload_builder->build($order);
			$request_payload = $this->encodeJson([
				'trigger' => $trigger,
				'create'  => $payload
			]);

			$this->awb_repository->save([
				'order_id'         => $order_id,
				'courier_code'     => Settings::COURIER_BOOKURIER,
				'panel_status'     => Settings::PANEL_STATUS_PENDING,
				'error_message'    => null,
				'request_payload'  => $request_payload,
				'response_payload' => $response_payload
			]);

			$client = $this->createClient();
			$create_response = $client->createAwb($payload, ['order_id' => $order_id]);
			$awb_code = $this->extractAwbCode($create_response);

			if ($awb_code === '') {
				throw new ApiException('Bookurier did not return an AWB code.');
			}

			$tracking_response = [];
			$provider_status = 'AWB created';

			if ($client->hasTrackingConfiguration()) {
				try {
					$tracking_response = $client->getAwbHistory($awb_code, ['order_id' => $order_id]);
					$provider_status = $this->extractLatestTrackingStatus($tracking_response) ?: $provider_status;
				} catch (\Throwable $exception) {
					$this->log('warning', 'Tracking refresh after AWB generation failed.', [
						'order_id' => $order_id,
						'awb_code' => $awb_code,
						'message'  => $exception->getMessage()
					]);
				}
			}

			$response_payload = $this->encodeJson([
				'create'   => $create_response,
				'tracking' => $tracking_response
			]);

			$this->awb_repository->save([
				'order_id'         => $order_id,
				'courier_code'     => Settings::COURIER_BOOKURIER,
				'awb_code'         => $awb_code,
				'provider_status'  => $provider_status,
				'panel_status'     => Settings::PANEL_STATUS_GENERATED,
				'error_message'    => null,
				'request_payload'  => $request_payload,
				'response_payload' => $response_payload
			]);

			$this->log('info', 'Bookurier AWB generated.', [
				'order_id' => $order_id,
				'awb_code' => $awb_code,
				'trigger'  => $trigger
			]);

			return $this->normalizeAwbRecord($this->awb_repository->getByOrderId($order_id));
		} catch (\Throwable $exception) {
			$this->awb_repository->save([
				'order_id'         => $order_id,
				'courier_code'     => Settings::COURIER_BOOKURIER,
				'panel_status'     => Settings::PANEL_STATUS_ERROR,
				'error_message'    => $exception->getMessage(),
				'request_payload'  => $request_payload,
				'response_payload' => $response_payload
			]);

			$this->log('error', 'Bookurier AWB generation failed.', [
				'order_id' => $order_id,
				'trigger'  => $trigger,
				'message'  => $exception->getMessage()
			]);

			throw $exception;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function refreshStatusForOrder(int $order_id): array {
		$awb = $this->normalizeAwbRecord($this->awb_repository->getByOrderId($order_id));

		if ($awb['awb_code'] === '') {
			throw new \RuntimeException('No Bookurier AWB exists for this order.');
		}

		$client = $this->createClient();
		$tracking_response = $client->getAwbHistory($awb['awb_code'], ['order_id' => $order_id]);
		$provider_status = $this->extractLatestTrackingStatus($tracking_response);
		$response_payload = $this->mergeResponsePayload($awb['response_payload'], 'tracking', $tracking_response);

		$this->awb_repository->save([
			'order_id'         => $order_id,
			'provider_status'  => $provider_status,
			'panel_status'     => $awb['panel_status'] ?: Settings::PANEL_STATUS_GENERATED,
			'error_message'    => null,
			'response_payload' => $response_payload
		]);

		$this->log('info', 'Bookurier tracking refreshed.', [
			'order_id'        => $order_id,
			'awb_code'        => $awb['awb_code'],
			'provider_status' => $provider_status
		]);

		return $this->normalizeAwbRecord($this->awb_repository->getByOrderId($order_id));
	}

	/**
	 * @return array<string, string>
	 */
	public function downloadLabelForOrder(int $order_id): array {
		$awb = $this->normalizeAwbRecord($this->awb_repository->getByOrderId($order_id));

		if ($awb['awb_code'] === '') {
			throw new \RuntimeException('No Bookurier AWB exists for this order.');
		}

		$content = $this->createClient()->printAwbs([$awb['awb_code']], 'pdf', 'm', 0, ['order_id' => $order_id]);

		return [
			'filename'     => 'bookurier-awb-' . $awb['awb_code'] . '.pdf',
			'content_type' => 'application/pdf',
			'content'      => $content
		];
	}

	public function log(string $level, string $message, array $context = []): void {
		$prefix = strtoupper($level);
		$payload = $context ? ' ' . $this->encodeJson($context) : '';

		$this->logger->write('[Bookurier][' . $prefix . '] ' . $message . $payload);
	}

	/**
	 * @param array<string, mixed> $order
	 */
	public function isBookurierOrder(array $order): bool {
		$shipping_code = strtolower((string)($order['shipping_method']['code'] ?? ''));
		$shipping_code_raw = strtolower((string)($order['shipping_code'] ?? ''));
		$shipping_raw = strtolower((string)($order['shipping_method_raw'] ?? ''));

		if ($shipping_code === 'bookurier.bookurier' || $shipping_code === 'bookurier') {
			return true;
		}

		if ($shipping_code_raw === 'bookurier.bookurier' || $shipping_code_raw === 'bookurier') {
			return true;
		}

		return strpos($shipping_raw, 'bookurier.bookurier') !== false
			|| strpos($shipping_raw, 'bookurier') !== false;
	}

	/**
	 * @return array<int, string>
	 */
	private function getConfigurationIssues(): array {
		$issues = [];

		if (trim((string)$this->config->get('module_bookurier_bookurier_username')) === '') {
			$issues[] = 'Bookurier username is missing.';
		}

		if (trim((string)$this->config->get('module_bookurier_bookurier_password')) === '') {
			$issues[] = 'Bookurier password is missing.';
		}

		if ((int)$this->config->get('module_bookurier_bookurier_pickup_point') <= 0) {
			$issues[] = 'Bookurier default pickup point is missing.';
		}

		if ((int)$this->config->get('module_bookurier_bookurier_service') <= 0) {
			$issues[] = 'Bookurier default service is missing.';
		}

		return $issues;
	}

	private function createClient(): BookurierClient {
		return new BookurierClient(
			(string)$this->config->get('module_bookurier_bookurier_username'),
			(string)$this->config->get('module_bookurier_bookurier_password'),
			(string)$this->config->get('module_bookurier_bookurier_api_key')
		);
	}

	/**
	 * @param array<string, mixed> $response
	 */
	private function extractAwbCode(array $response): string {
		$data = $response['data'] ?? [];

		if (!is_array($data)) {
			return '';
		}

		foreach ($data as $value) {
			$awb_code = trim((string)$value);

			if ($awb_code !== '') {
				return $awb_code;
			}
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $response
	 */
	private function extractLatestTrackingStatus(array $response): string {
		$data = $response['data'] ?? [];

		if (!is_array($data) || !$data) {
			return '';
		}

		if ($this->isTrackingEvent($data)) {
			return trim((string)($data['status_name'] ?? ''));
		}

		$events = [];

		foreach ($data as $event) {
			if (is_array($event)) {
				$events[] = $event;
			}
		}

		if (!$events) {
			return '';
		}

		usort($events, static function(array $left, array $right): int {
			$left_value = (string)($left['sort_date'] ?? $left['data'] ?? '');
			$right_value = (string)($right['sort_date'] ?? $right['data'] ?? '');

			return strcmp($left_value, $right_value);
		});

		$event = end($events);

		if (!is_array($event)) {
			return '';
		}

		return trim((string)($event['status_name'] ?? ''));
	}

	/**
	 * @param mixed $event
	 */
	private function isTrackingEvent($event): bool {
		return is_array($event) && isset($event['status_name']);
	}

	/**
	 * @param array<string, mixed> $order
	 */
	private function resolveShippingMethodName(array $order): string {
		$name = trim((string)($order['shipping_method']['name'] ?? $order['shipping_method']['title'] ?? ''));

		if ($name === '') {
			$name = trim((string)($order['shipping_method_raw'] ?? ''));
		}

		if ($name === '') {
			$name = trim((string)($order['shipping_code'] ?? ''));
		}

		return $name;
	}

	/**
	 * @param array<string, mixed> $awb
	 *
	 * @return array<string, mixed>
	 */
	private function normalizeAwbRecord(array $awb): array {
		return [
			'order_id'         => (int)($awb['order_id'] ?? 0),
			'courier_code'     => (string)($awb['courier_code'] ?? Settings::COURIER_BOOKURIER),
			'awb_code'         => (string)($awb['awb_code'] ?? ''),
			'locker_id'        => (string)($awb['locker_id'] ?? ''),
			'provider_status'  => (string)($awb['provider_status'] ?? ''),
			'panel_status'     => (string)($awb['panel_status'] ?? ''),
			'error_message'    => (string)($awb['error_message'] ?? ''),
			'request_payload'  => (string)($awb['request_payload'] ?? ''),
			'response_payload' => (string)($awb['response_payload'] ?? ''),
			'date_added'       => (string)($awb['date_added'] ?? ''),
			'date_modified'    => (string)($awb['date_modified'] ?? '')
		];
	}

	/**
	 * @param mixed $data
	 */
	private function encodeJson($data): string {
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return $json === false ? '{}' : $json;
	}

	/**
	 * @param mixed $payload
	 */
	private function mergeResponsePayload(string $existing_payload, string $key, $payload): string {
		$data = json_decode($existing_payload, true);

		if (!is_array($data)) {
			$data = [];
		}

		$data[$key] = $payload;

		return $this->encodeJson($data);
	}
}
