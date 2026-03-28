<?php
namespace Opencart\Admin\Controller\Extension\Bookurier\Module;

use Opencart\System\Library\Extension\Bookurier\SamedayLockerRepository;
use Opencart\System\Library\Extension\Bookurier\SamedayLockerSyncService;
use Opencart\System\Library\Extension\Bookurier\SamedayPickupPointSyncService;
use Opencart\System\Library\Extension\Bookurier\Settings;

class Bookurier extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$this->load->language('extension/bookurier/module/bookurier');
		$this->ensureSchema();

		$this->document->setTitle($this->language->get('heading_title'));

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module')
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/bookurier/module/bookurier', 'user_token=' . $this->session->data['user_token'])
		];

		$data['save'] = $this->url->link('extension/bookurier/module/bookurier.save', 'user_token=' . $this->session->data['user_token']);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module');

		$this->load->model('localisation/order_status');

		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
		$module_defaults = Settings::moduleDefaults();
		$module_defaults['module_bookurier_auto_awb_status_ids'] = $this->resolveDefaultAutoAwbStatusIds($data['order_statuses']);

		$data['module_bookurier_bookurier_services'] = Settings::bookurierServiceOptions();
		$data['module_bookurier_sameday_environments'] = [
			[
				'code' => 'prod',
				'text' => $this->language->get('text_environment_production')
			],
			[
				'code' => 'demo',
				'text' => $this->language->get('text_environment_demo')
			]
		];
		$data['module_bookurier_sameday_package_types'] = [
			[
				'code' => '0',
				'text' => $this->language->get('text_package_type_parcel')
			],
			[
				'code' => '1',
				'text' => $this->language->get('text_package_type_envelope')
			],
			[
				'code' => '2',
				'text' => $this->language->get('text_package_type_large_package')
			]
		];

		foreach ($module_defaults as $key => $default) {
			$data[$key] = $this->getConfigValue($key, $default);
		}

		$locker_repository = new SamedayLockerRepository($this->db);

		$data['module_bookurier_sameday_pickup_points'] = $this->normalizePickupPointOptions(
			$this->decodeJsonArray((string)$data['module_bookurier_sameday_pickup_points_cache']),
			(int)$data['module_bookurier_sameday_pickup_point']
		);
		$data['module_bookurier_sameday_lockers_count'] = $locker_repository->countActive();
		$data['sync_pickup_points'] = $this->url->link('extension/bookurier/module/bookurier.syncPickupPoints', 'user_token=' . $this->session->data['user_token']);
		$data['sync_lockers'] = $this->url->link('extension/bookurier/module/bookurier.syncLockers', 'user_token=' . $this->session->data['user_token']);
		$data['module_bookurier_bookurier_password'] = '';
		$data['module_bookurier_sameday_password'] = '';

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/bookurier/module/bookurier', $data));
	}

	public function save(): void {
		$this->load->language('extension/bookurier/module/bookurier');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/bookurier/module/bookurier')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$this->load->model('setting/setting');

			$this->model_setting_setting->editSetting(Settings::MODULE_SETTING_CODE, $this->collectSettings());

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function syncPickupPoints(): void {
		$this->load->language('extension/bookurier/module/bookurier');
		$this->ensureSchema();

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/bookurier/module/bookurier')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			try {
				$credentials = $this->resolveSamedayCredentials();
				$service = new SamedayPickupPointSyncService();
				$result = $service->sync(
					$credentials['username'],
					$credentials['password'],
					$credentials['environment'],
					(int)($this->request->post['module_bookurier_sameday_pickup_point'] ?? $this->config->get('module_bookurier_sameday_pickup_point'))
				);

				$this->saveModuleSettings([
					'module_bookurier_sameday_pickup_point' => (string)$result['selected_id'],
					'module_bookurier_sameday_pickup_points_cache' => $this->encodeJson($result['pickup_points']),
					'module_bookurier_sameday_pickup_points_synced_at' => (string)$result['synced_at']
				]);

				$json['success'] = sprintf($this->language->get('text_sync_pickup_points_success'), (int)$result['count']);
				$json['pickup_points'] = $result['pickup_points'];
				$json['selected_id'] = (int)$result['selected_id'];
				$json['synced_at'] = (string)$result['synced_at'];
			} catch (\Throwable $exception) {
				$json['error'] = $exception->getMessage();
			}
		}

		$this->jsonResponse($json);
	}

	public function syncLockers(): void {
		$this->load->language('extension/bookurier/module/bookurier');
		$this->ensureSchema();

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/bookurier/module/bookurier')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			try {
				$credentials = $this->resolveSamedayCredentials();
				$service = new SamedayLockerSyncService($this->db);
				$result = $service->sync(
					$credentials['username'],
					$credentials['password'],
					$credentials['environment']
				);

				$this->saveModuleSettings([
					'module_bookurier_sameday_lockers_count' => (string)$result['count'],
					'module_bookurier_sameday_lockers_synced_at' => (string)$result['synced_at']
				]);

				$json['success'] = sprintf($this->language->get('text_sync_lockers_success'), (int)$result['count']);
				$json['count'] = (int)$result['count'];
				$json['synced_at'] = (string)$result['synced_at'];
			} catch (\Throwable $exception) {
				$json['error'] = $exception->getMessage();
			}
		}

		$this->jsonResponse($json);
	}

	public function install(): void {
		$this->load->model('extension/bookurier/setup');

		$this->model_extension_bookurier_setup->installModule();
	}

	public function uninstall(): void {
		$this->load->model('extension/bookurier/setup');

		$this->model_extension_bookurier_setup->uninstallModule();
	}

	/**
	 * @param mixed $default
	 *
	 * @return mixed
	 */
	private function getConfigValue(string $key, $default) {
		if (isset($this->request->post[$key])) {
			return $this->request->post[$key];
		}

		$value = $this->config->get($key);

		if ($value === null) {
			return $default;
		}

		if (is_string($value) && $value === '' && $default !== '') {
			return $default;
		}

		return $value;
	}

	private function ensureSchema(): void {
		$this->load->model('extension/bookurier/setup');

		$this->model_extension_bookurier_setup->installSchema();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function collectSettings(): array {
		$data = [];
		$post = $this->request->post;
		$current_password = (string)$this->config->get('module_bookurier_bookurier_password');
		$current_sameday_password = (string)$this->config->get('module_bookurier_sameday_password');
		$internal_keys = Settings::internalModuleSettingKeys();

		foreach (Settings::moduleDefaults() as $key => $default) {
			if (is_array($default)) {
				$value = $post[$key] ?? [];
				$value = is_array($value) ? $value : [];
				$value = array_values(array_unique(array_filter(array_map('intval', $value))));
			} elseif (in_array($key, $internal_keys, true)) {
				$value = $this->config->get($key);
				$value = $value !== null ? $value : $default;
			} elseif ($key === 'module_bookurier_bookurier_password') {
				$input = (string)($post[$key] ?? '');
				$value = $input !== '' ? $input : $current_password;
			} elseif ($key === 'module_bookurier_sameday_password') {
				$input = (string)($post[$key] ?? '');
				$value = $input !== '' ? $input : $current_sameday_password;
			} elseif (in_array($key, ['module_bookurier_status', 'module_bookurier_sameday_enabled', 'module_bookurier_auto_awb_enabled'])) {
				$value = !empty($post[$key]) ? 1 : 0;
			} else {
				$value = trim((string)($post[$key] ?? $default));
			}

			$data[$key] = $value;
		}

		if (!in_array($data['module_bookurier_sameday_environment'], ['prod', 'demo'], true)) {
			$data['module_bookurier_sameday_environment'] = 'prod';
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $json
	 */
	private function jsonResponse(array $json): void {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function saveModuleSettings(array $values): void {
		$this->load->model('setting/setting');

		$current = $this->model_setting_setting->getSetting(Settings::MODULE_SETTING_CODE);
		$this->model_setting_setting->editSetting(Settings::MODULE_SETTING_CODE, array_replace($current, $values));
	}

	/**
	 * @return array<string, string>
	 */
	private function resolveSamedayCredentials(): array {
		$username = trim((string)($this->request->post['module_bookurier_sameday_username'] ?? $this->config->get('module_bookurier_sameday_username')));
		$password_input = trim((string)($this->request->post['module_bookurier_sameday_password'] ?? ''));
		$password = $password_input !== '' ? $password_input : trim((string)$this->config->get('module_bookurier_sameday_password'));
		$environment = trim((string)($this->request->post['module_bookurier_sameday_environment'] ?? $this->config->get('module_bookurier_sameday_environment')));
		$environment = $environment === Settings::SAMEDAY_ENV_DEMO ? Settings::SAMEDAY_ENV_DEMO : Settings::SAMEDAY_ENV_PROD;

		if ($username === '' || $password === '') {
			throw new \RuntimeException('SameDay username or password is missing.');
		}

		return [
			'username'    => $username,
			'password'    => $password,
			'environment' => $environment
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function decodeJsonArray(string $value): array {
		$data = json_decode($value, true);

		return is_array($data) ? $data : [];
	}

	/**
	 * @param array<int, array<string, mixed>> $pickup_points
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function normalizePickupPointOptions(array $pickup_points, int $selected_id = 0): array {
		$options = [];
		$has_selected = false;

		foreach ($pickup_points as $pickup_point) {
			$id = (int)($pickup_point['id'] ?? 0);

			if ($id <= 0) {
				continue;
			}

			$options[] = [
				'id'      => $id,
				'name'    => trim((string)($pickup_point['name'] ?? '')),
				'default' => !empty($pickup_point['default'])
			];

			if ($id === $selected_id) {
				$has_selected = true;
			}
		}

		if ($selected_id > 0 && !$has_selected) {
			$options[] = [
				'id'      => $selected_id,
				'name'    => '[' . $selected_id . '] ' . $this->language->get('text_pickup_point_saved'),
				'default' => false
			];
		}

		return $options;
	}

	/**
	 * @param mixed $data
	 */
	private function encodeJson($data): string {
		$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return $json === false ? '[]' : $json;
	}

	/**
	 * @param array<int, array<string, mixed>> $order_statuses
	 *
	 * @return array<int, int>
	 */
	private function resolveDefaultAutoAwbStatusIds(array $order_statuses): array {
		$preferred = ['processing', 'processed', 'shipped'];
		$lookup = [];

		foreach ($order_statuses as $order_status) {
			$name = strtolower(trim((string)($order_status['name'] ?? '')));

			if ($name !== '' && !isset($lookup[$name])) {
				$lookup[$name] = (int)$order_status['order_status_id'];
			}
		}

		$status_ids = [];

		foreach ($preferred as $name) {
			if (!empty($lookup[$name])) {
				$status_ids[] = (int)$lookup[$name];
			}
		}

		return array_values(array_unique(array_filter($status_ids)));
	}
}
