<?php
require_once DIR_SYSTEM . 'library/extension/bookurier_bootstrap.php';

class ControllerExtensionModuleBookurierEvent extends Controller {
	public function orderInfoBefore(&$route, &$data, &$output) {
		$order_id = (int)($data['order_id'] ?? 0);

		if ($order_id <= 0) {
			return;
		}

		$this->load->language('extension/module/bookurier/awb');

		if (!isset($data['tabs']) || !is_array($data['tabs'])) {
			$data['tabs'] = [];
		}

		$data['tabs'][] = [
			'code' => 'bookurier_awb',
			'title' => $this->language->get('tab_bookurier_awb'),
			'content' => $this->load->controller('extension/module/bookurier/awb/tab', $order_id)
		];
	}
}
