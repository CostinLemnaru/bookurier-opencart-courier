<?php
namespace Opencart\Admin\Controller\Extension\Bookurier\Shipping;

use Opencart\System\Library\Extension\Bookurier\Settings;

class SamedayLocker extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$this->load->language('extension/bookurier/shipping/sameday_locker');

		$this->document->setTitle($this->language->get('heading_title'));

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping')
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/bookurier/shipping/sameday_locker', 'user_token=' . $this->session->data['user_token'])
		];

		$data['save'] = $this->url->link('extension/bookurier/shipping/sameday_locker.save', 'user_token=' . $this->session->data['user_token']);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping');

		$this->load->model('localisation/tax_class');
		$this->load->model('localisation/geo_zone');

		$data['tax_classes'] = $this->model_localisation_tax_class->getTaxClasses();
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		foreach (Settings::shippingDefaults(Settings::SHIPPING_SAMEDAY_LOCKER_CODE) as $key => $default) {
			$data[$key] = $this->getConfigValue($key, $default);
		}

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/bookurier/shipping/sameday_locker', $data));
	}

	public function save(): void {
		$this->load->language('extension/bookurier/shipping/sameday_locker');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/bookurier/shipping/sameday_locker')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$this->load->model('setting/setting');

			$this->model_setting_setting->editSetting(Settings::SHIPPING_SAMEDAY_LOCKER_CODE, $this->collectSettings());

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function install(): void {
		$this->load->model('extension/bookurier/setup');

		$this->model_extension_bookurier_setup->installShipping(Settings::SHIPPING_SAMEDAY_LOCKER_CODE);
	}

	public function uninstall(): void {
		$this->load->model('extension/bookurier/setup');

		$this->model_extension_bookurier_setup->uninstallShipping();
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

		return $value !== null ? $value : $default;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function collectSettings(): array {
		$defaults = Settings::shippingDefaults(Settings::SHIPPING_SAMEDAY_LOCKER_CODE);

		return [
			'shipping_sameday_locker_title'        => trim((string)($this->request->post['shipping_sameday_locker_title'] ?? $defaults['shipping_sameday_locker_title'])),
			'shipping_sameday_locker_cost'         => (string)($this->request->post['shipping_sameday_locker_cost'] ?? $defaults['shipping_sameday_locker_cost']),
			'shipping_sameday_locker_tax_class_id' => (int)($this->request->post['shipping_sameday_locker_tax_class_id'] ?? $defaults['shipping_sameday_locker_tax_class_id']),
			'shipping_sameday_locker_geo_zone_id'  => (int)($this->request->post['shipping_sameday_locker_geo_zone_id'] ?? $defaults['shipping_sameday_locker_geo_zone_id']),
			'shipping_sameday_locker_status'       => !empty($this->request->post['shipping_sameday_locker_status']) ? 1 : 0,
			'shipping_sameday_locker_sort_order'   => (int)($this->request->post['shipping_sameday_locker_sort_order'] ?? $defaults['shipping_sameday_locker_sort_order'])
		];
	}
}
