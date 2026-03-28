<?php
require_once DIR_SYSTEM . 'library/extension/bookurier_bootstrap.php';

use Opencart\System\Library\Extension\Bookurier\Settings;

class ControllerExtensionShippingBookurier extends Controller {
	private $error = [];

	public function index() {
		$this->load->language('extension/shipping/bookurier');
		$this->document->setTitle($this->language->get('heading_title'));
		$this->load->model('setting/setting');

		if (($this->request->server['REQUEST_METHOD'] === 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting(Settings::SHIPPING_BOOKURIER_CODE, $this->collectSettings());
			$this->session->data['success'] = $this->language->get('text_success');
			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping', true));

			return;
		}

		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
		$data['breadcrumbs'] = [];
		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		];
		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping', true)
		];
		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/shipping/bookurier', 'user_token=' . $this->session->data['user_token'], true)
		];
		$data['action'] = $this->url->link('extension/shipping/bookurier', 'user_token=' . $this->session->data['user_token'], true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping', true);

		$this->load->model('localisation/tax_class');
		$this->load->model('localisation/geo_zone');
		$data['tax_classes'] = $this->model_localisation_tax_class->getTaxClasses();
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		foreach (Settings::shippingDefaults(Settings::SHIPPING_BOOKURIER_CODE) as $key => $default) {
			$data[$key] = $this->getConfigValue($key, $default);
		}

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/shipping/bookurier', $data));
	}

	public function install() {
		$this->load->model('extension/module/bookurier_setup');
		$this->model_extension_module_bookurier_setup->installShipping(Settings::SHIPPING_BOOKURIER_CODE);
	}

	public function uninstall() {
		$this->load->model('extension/module/bookurier_setup');
		$this->model_extension_module_bookurier_setup->uninstallShipping();
	}

	protected function validate() {
		if (!$this->user->hasPermission('modify', 'extension/shipping/bookurier')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		return !$this->error;
	}

	private function getConfigValue($key, $default) {
		if (isset($this->request->post[$key])) {
			return $this->request->post[$key];
		}

		$value = $this->config->get($key);

		return $value !== null ? $value : $default;
	}

	private function collectSettings() {
		$defaults = Settings::shippingDefaults(Settings::SHIPPING_BOOKURIER_CODE);

		return [
			'shipping_bookurier_title' => trim((string)($this->request->post['shipping_bookurier_title'] ?? $defaults['shipping_bookurier_title'])),
			'shipping_bookurier_cost' => (string)($this->request->post['shipping_bookurier_cost'] ?? $defaults['shipping_bookurier_cost']),
			'shipping_bookurier_tax_class_id' => (int)($this->request->post['shipping_bookurier_tax_class_id'] ?? $defaults['shipping_bookurier_tax_class_id']),
			'shipping_bookurier_geo_zone_id' => (int)($this->request->post['shipping_bookurier_geo_zone_id'] ?? $defaults['shipping_bookurier_geo_zone_id']),
			'shipping_bookurier_status' => !empty($this->request->post['shipping_bookurier_status']) ? 1 : 0,
			'shipping_bookurier_sort_order' => (int)($this->request->post['shipping_bookurier_sort_order'] ?? $defaults['shipping_bookurier_sort_order'])
		];
	}
}
