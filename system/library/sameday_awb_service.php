<?php
namespace Opencart\System\Library\Extension\Bookurier;

class SamedayAwbService {
	private object $config;
	private object $db;
	private object $logger;
	private AwbRepository $awb_repository;
	private BookurierOrderRepository $order_repository;
	private SamedayLockerRepository $locker_repository;
	private SamedayLockerSelectionRepository $selection_repository;
	private SamedayPayloadBuilder $payload_builder;
	private ?array $service_id_map = null;

	public function __construct(object $registry) {
		$this->config = $registry->get('config');
		$this->db = $registry->get('db');
		$this->logger = Platform::createLog(Settings::LOG_FILE);
		$this->awb_repository = new AwbRepository($this->db);
		$this->order_repository = new BookurierOrderRepository($registry);
		$this->locker_repository = new SamedayLockerRepository($this->db);
		$this->selection_repository = new SamedayLockerSelectionRepository($this->db);
		$this->payload_builder = new SamedayPayloadBuilder($registry, $this->order_repository);
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
		$is_sameday_order = $this->isSamedayOrder($order);
		$locker_id = $this->resolveLockerId($order_id, $awb);
		$locker = $locker_id !== '' ? $this->locker_repository->findActiveLockerById($locker_id) : null;
		$has_awb = $awb['awb_code'] !== '';

		if ($is_sameday_order && !$has_awb && $locker_id === '') {
			$configuration_issues[] = 'No SameDay locker selected for this order.';
		} elseif ($is_sameday_order && !$has_awb && !$locker) {
			$configuration_issues[] = 'Selected SameDay locker is no longer active.';
		}

		return [
			'order_id'              => $order_id,
			'order_status_name'     => (string)($order['order_status_name'] ?? ''),
			'shipping_method_name'  => $this->resolveShippingMethodName($order),
			'is_supported_order'    => $is_sameday_order,
			'configuration_issues'  => array_values(array_unique(array_filter($configuration_issues))),
			'is_configured'         => empty($configuration_issues),
			'has_tracking'          => empty($this->getConfigurationIssues()),
			'can_generate'          => $is_sameday_order && !$has_awb && empty($configuration_issues),
			'can_download'          => $has_awb,
			'can_refresh'           => $has_awb && empty($this->getConfigurationIssues()),
			'awb'                   => $awb,
			'locker_id'             => $locker_id,
			'locker_label'          => $this->resolveLockerLabel($locker)
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

		if (!$this->isSamedayOrder($order)) {
			throw new \RuntimeException('Order shipping method is not SameDay Locker.');
		}

		$existing = $this->normalizeAwbRecord($this->awb_repository->getByOrderId($order_id));

		if ($existing['awb_code'] !== '') {
			if ($allow_existing) {
				return $existing;
			}

			throw new \RuntimeException('AWB already exists for this order.');
		}

		$issues = $this->getConfigurationIssues();
		$locker_id = $this->resolveLockerId($order_id, $existing);

		if ($locker_id === '') {
			$issues[] = 'No SameDay locker selected for this order.';
		}

		$locker = $locker_id !== '' ? $this->locker_repository->findActiveLockerById($locker_id) : null;

		if ($locker_id !== '' && !$locker) {
			$issues[] = 'Selected SameDay locker is no longer active.';
		}

		if ($issues) {
			throw new \RuntimeException(implode(' ', array_values(array_unique($issues))));
		}

		$request_payload = '';
		$response_payload = '';

		try {
			$client = $this->createClient();
			$service_candidates = $this->resolveServiceCandidates($locker ?? [], $client);
			$last_exception = null;

			$this->awb_repository->save([
				'order_id'         => $order_id,
				'courier_code'     => Settings::COURIER_SAMEDAY_LOCKER,
				'locker_id'        => $locker_id,
				'panel_status'     => Settings::PANEL_STATUS_PENDING,
				'error_message'    => null,
				'request_payload'  => '',
				'response_payload' => ''
			]);

			foreach ($service_candidates as $index => $service_id) {
				$is_last_candidate = $index === count($service_candidates) - 1;
				$payload = $this->payload_builder->build($order, $locker ?? [], $service_id);
				$request_payload = $this->encodeJson([
					'trigger'            => $trigger,
					'service_candidates' => $service_candidates,
					'create'             => $payload
				]);

				$this->awb_repository->save([
					'order_id'         => $order_id,
					'courier_code'     => Settings::COURIER_SAMEDAY_LOCKER,
					'locker_id'        => $locker_id,
					'panel_status'     => Settings::PANEL_STATUS_PENDING,
					'error_message'    => null,
					'request_payload'  => $request_payload,
					'response_payload' => ''
				]);

				try {
					$create_response = $client->createAwb($payload, ['order_id' => $order_id]);
					$awb_code = $this->extractAwbCode($create_response);

					if ($awb_code === '') {
						throw new ApiException($this->extractApiMessage($create_response, 'SameDay did not return an AWB code.'));
					}

					$status_response = [];
					$provider_status = 'AWB created';

					try {
						$status_response = $client->getAwbStatus($awb_code, ['order_id' => $order_id]);
						$provider_status = $this->extractLatestTrackingStatus($status_response) ?: $provider_status;
					} catch (\Throwable $exception) {
						$this->log('warning', 'SameDay tracking refresh after AWB generation failed.', [
							'order_id' => $order_id,
							'awb_code' => $awb_code,
							'message'  => $exception->getMessage()
						]);
					}

					$response_payload = $this->encodeJson([
						'create'   => $create_response,
						'tracking' => $status_response
					]);

					$this->awb_repository->save([
						'order_id'         => $order_id,
						'courier_code'     => Settings::COURIER_SAMEDAY_LOCKER,
						'awb_code'         => $awb_code,
						'locker_id'        => $locker_id,
						'provider_status'  => $provider_status,
						'panel_status'     => Settings::PANEL_STATUS_GENERATED,
						'error_message'    => null,
						'request_payload'  => $request_payload,
						'response_payload' => $response_payload
					]);

					$this->log('info', 'SameDay AWB generated.', [
						'order_id' => $order_id,
						'awb_code' => $awb_code,
						'trigger'  => $trigger
					]);

					return $this->normalizeAwbRecord($this->awb_repository->getByOrderId($order_id));
				} catch (\Throwable $exception) {
					$last_exception = $exception;

					if ($is_last_candidate || !$this->isValidationFailure($exception)) {
						throw $exception;
					}
				}
			}

			if ($last_exception) {
				throw $last_exception;
			}

			throw new \RuntimeException('SameDay AWB could not be generated.');
		} catch (\Throwable $exception) {
			$this->awb_repository->save([
				'order_id'         => $order_id,
				'courier_code'     => Settings::COURIER_SAMEDAY_LOCKER,
				'locker_id'        => $locker_id,
				'panel_status'     => Settings::PANEL_STATUS_ERROR,
				'error_message'    => $exception->getMessage(),
				'request_payload'  => $request_payload,
				'response_payload' => $response_payload
			]);

			$this->log('error', 'SameDay AWB generation failed.', [
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
			throw new \RuntimeException('No SameDay AWB exists for this order.');
		}

		$status_response = $this->createClient()->getAwbStatus($awb['awb_code'], ['order_id' => $order_id]);
		$provider_status = $this->extractLatestTrackingStatus($status_response);
		$response_payload = $this->mergeResponsePayload($awb['response_payload'], 'tracking', $status_response);

		$this->awb_repository->save([
			'order_id'         => $order_id,
			'provider_status'  => $provider_status,
			'panel_status'     => $awb['panel_status'] ?: Settings::PANEL_STATUS_GENERATED,
			'error_message'    => null,
			'response_payload' => $response_payload
		]);

		$this->log('info', 'SameDay tracking refreshed.', [
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
			throw new \RuntimeException('No SameDay AWB exists for this order.');
		}

		$content = $this->createClient()->downloadAwbPdf($awb['awb_code'], 'A6', ['order_id' => $order_id]);

		return [
			'filename'     => 'sameday-awb-' . $awb['awb_code'] . '.pdf',
			'content_type' => 'application/pdf',
			'content'      => $content
		];
	}

	public function log(string $level, string $message, array $context = []): void {
		$prefix = strtoupper($level);
		$payload = $context ? ' ' . $this->encodeJson($context) : '';

		$this->logger->write('[SameDay][' . $prefix . '] ' . $message . $payload);
	}

	/**
	 * @param array<string, mixed> $order
	 */
	public function isSamedayOrder(array $order): bool {
		$shipping_code = strtolower((string)($order['shipping_method']['code'] ?? ''));
		$shipping_code_raw = strtolower((string)($order['shipping_code'] ?? ''));
		$shipping_raw = strtolower((string)($order['shipping_method_raw'] ?? ''));

		if ($shipping_code === 'sameday_locker.sameday_locker' || $shipping_code === 'sameday_locker') {
			return true;
		}

		if ($shipping_code_raw === 'sameday_locker.sameday_locker' || $shipping_code_raw === 'sameday_locker') {
			return true;
		}

		return strpos($shipping_raw, 'sameday_locker.sameday_locker') !== false
			|| strpos($shipping_raw, 'sameday locker') !== false
			|| strpos($shipping_raw, 'sameday_locker') !== false;
	}

	/**
	 * @return array<int, string>
	 */
	private function getConfigurationIssues(): array {
		$issues = [];

		if (!(int)$this->config->get('module_bookurier_sameday_enabled')) {
			$issues[] = 'SameDay Locker is disabled.';
		}

		if (trim((string)$this->config->get('module_bookurier_sameday_username')) === '') {
			$issues[] = 'SameDay username is missing.';
		}

		if (trim((string)$this->config->get('module_bookurier_sameday_password')) === '') {
			$issues[] = 'SameDay password is missing.';
		}

		if ((int)$this->config->get('module_bookurier_sameday_pickup_point') <= 0) {
			$issues[] = 'SameDay pickup point is missing.';
		}

		if (!in_array((int)$this->config->get('module_bookurier_sameday_package_type'), [0, 1, 2], true)) {
			$issues[] = 'SameDay package type is invalid.';
		}

		return $issues;
	}

	private function createClient(): SamedayClient {
		return new SamedayClient(
			(string)$this->config->get('module_bookurier_sameday_username'),
			(string)$this->config->get('module_bookurier_sameday_password'),
			(string)$this->config->get('module_bookurier_sameday_environment')
		);
	}

	/**
	 * @param array<string, mixed> $response
	 */
	private function extractAwbCode(array $response): string {
		$awb_code = trim((string)($response['awbNumber'] ?? ''));

		if ($awb_code !== '') {
			return $awb_code;
		}

		if (!empty($response['data']) && is_array($response['data'])) {
			$awb_code = trim((string)($response['data']['awbNumber'] ?? ''));
		}

		return $awb_code;
	}

	/**
	 * @param array<string, mixed> $response
	 */
	private function extractApiMessage(array $response, string $fallback): string {
		foreach (['message', 'error_description', 'description'] as $key) {
			$message = trim((string)($response[$key] ?? ''));

			if ($message !== '') {
				return $message;
			}
		}

		if (!empty($response['error']) && is_array($response['error'])) {
			foreach (['message', 'description'] as $key) {
				$message = trim((string)($response['error'][$key] ?? ''));

				if ($message !== '') {
					return $message;
				}
			}
		}

		return $fallback;
	}

	/**
	 * @param array<string, mixed> $response
	 */
	private function extractLatestTrackingStatus(array $response): string {
		$status = $this->extractStatusFromNode($response);

		if ($status !== '') {
			return $status;
		}

		$data = $response['data'] ?? [];

		if (is_array($data)) {
			$status = $this->extractStatusFromNode($data);

			if ($status !== '') {
				return $status;
			}

			foreach (['history', 'statuses', 'events'] as $key) {
				if (!empty($data[$key]) && is_array($data[$key])) {
					$status = $this->extractStatusFromList($data[$key]);

					if ($status !== '') {
						return $status;
					}
				}
			}

			return $this->extractStatusFromList($data);
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $locker
	 * @param array<string, mixed> $response
	 *
	 * @return array<int, int>
	 */
	private function resolveServiceCandidates(array $locker, SamedayClient $client): array {
		$service_ids = $this->resolveServiceIdMap($client);
		$locker_service = (int)($service_ids['LN'] ?? 15);
		$home_service = (int)($service_ids['LH'] ?? 17);
		$box_count = (int)($locker['box_count'] ?? 0);
		$candidates = $box_count > 0 ? [$locker_service, $home_service] : [$home_service, $locker_service];
		$resolved = [];

		foreach ($candidates as $candidate) {
			$candidate = (int)$candidate;

			if ($candidate > 0 && !in_array($candidate, $resolved, true)) {
				$resolved[] = $candidate;
			}
		}

		return $resolved ?: [15, 17];
	}

	/**
	 * @return array<string, int>
	 */
	private function resolveServiceIdMap(SamedayClient $client): array {
		if ($this->service_id_map !== null) {
			return $this->service_id_map;
		}

		$defaults = [
			'LN' => 15,
			'LH' => 17
		];
		$cached = json_decode((string)$this->config->get('module_bookurier_sameday_services_cache'), true);

		if (is_array($cached) && !empty($cached['LN']) && !empty($cached['LH'])) {
			$this->service_id_map = [
				'LN' => (int)$cached['LN'],
				'LH' => (int)$cached['LH']
			];

			return $this->service_id_map;
		}

		$resolved = $defaults;

		try {
			$services = $client->getServices(1, 500);

			foreach ($services as $service) {
				$service_id = (int)($service['id'] ?? 0);
				$service_code = strtoupper(trim((string)($service['serviceCode'] ?? $service['code'] ?? '')));

				if ($service_id > 0 && isset($resolved[$service_code])) {
					$resolved[$service_code] = $service_id;
				}
			}

			$this->saveSettingValue('module_bookurier_sameday_services_cache', json_encode($resolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
		} catch (\Throwable $exception) {
			$this->log('warning', 'SameDay services cache refresh failed.', [
				'message' => $exception->getMessage()
			]);
		}

		$this->service_id_map = $resolved;

		return $this->service_id_map;
	}

	private function saveSettingValue(string $key, string $value): void {
		$query = $this->db->query("SELECT `setting_id` FROM `" . DB_PREFIX . "setting` "
			. "WHERE `store_id` = '0' AND `code` = '" . $this->db->escape(Settings::MODULE_SETTING_CODE) . "' "
			. "AND `key` = '" . $this->db->escape($key) . "' LIMIT 1");

		if ($query->num_rows) {
			$this->db->query("UPDATE `" . DB_PREFIX . "setting` SET "
				. "`value` = '" . $this->db->escape($value) . "', "
				. "`serialized` = '0' "
				. "WHERE `setting_id` = '" . (int)$query->row['setting_id'] . "'");
		} else {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET "
				. "`store_id` = '0', "
				. "`code` = '" . $this->db->escape(Settings::MODULE_SETTING_CODE) . "', "
				. "`key` = '" . $this->db->escape($key) . "', "
				. "`value` = '" . $this->db->escape($value) . "', "
				. "`serialized` = '0'");
		}
	}

	private function isValidationFailure(\Throwable $exception): bool {
		$message = strtolower($exception->getMessage());

		return strpos($message, 'validation failed') !== false
			|| strpos($message, 'http 400') !== false;
	}

	/**
	 * @param array<string, mixed> $awb
	 */
	private function resolveLockerId(int $order_id, array $awb): string {
		$locker_id = trim((string)($awb['locker_id'] ?? ''));

		if ($locker_id !== '') {
			return $locker_id;
		}

		return $this->selection_repository->getLockerIdByOrderId($order_id);
	}

	/**
	 * @param array<string, mixed>|null $locker
	 */
	private function resolveLockerLabel(?array $locker): string {
		if (!$locker) {
			return '';
		}

		$name = trim((string)($locker['name'] ?? ''));
		$city = trim((string)($locker['city'] ?? ''));
		$county = trim((string)($locker['county'] ?? ''));

		return trim($name . ($city !== '' ? ' (' . $city . ($county !== '' ? ', ' . $county : '') . ')' : ''));
	}

	/**
	 * @param array<string, mixed> $node
	 */
	private function extractStatusFromNode(array $node): string {
		foreach (['statusLabel', 'status_name', 'statusName', 'statusText', 'stateName', 'label', 'name'] as $key) {
			$value = trim((string)($node[$key] ?? ''));

			if ($value !== '' && strtolower($value) !== 'success') {
				return $value;
			}
		}

		return '';
	}

	/**
	 * @param array<int|string, mixed> $items
	 */
	private function extractStatusFromList(array $items): string {
		$nodes = [];

		foreach ($items as $item) {
			if (is_array($item)) {
				$nodes[] = $item;
			}
		}

		for ($index = count($nodes) - 1; $index >= 0; $index--) {
			$status = $this->extractStatusFromNode($nodes[$index]);

			if ($status !== '') {
				return $status;
			}
		}

		return '';
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
			'courier_code'     => (string)($awb['courier_code'] ?? Settings::COURIER_SAMEDAY_LOCKER),
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
