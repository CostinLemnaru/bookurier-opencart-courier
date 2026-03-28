<?php
namespace Opencart\Admin\Controller\Extension\Bookurier\Event;

class Bookurier extends \Opencart\System\Engine\Controller {
	public function orderInfoBefore(string &$route, array &$data, string &$code, string &$output): void {
		$order_id = (int)($data['order_id'] ?? 0);

		if ($order_id <= 0) {
			return;
		}

		$this->load->language('extension/bookurier/sale/order_awb');

		if (!isset($data['tabs']) || !is_array($data['tabs'])) {
			$data['tabs'] = [];
		}

		$data['tabs'][] = [
			'code'    => 'bookurier_awb',
			'title'   => $this->language->get('tab_bookurier_awb'),
			'content' => $this->load->controller('extension/bookurier/sale/order_awb.tab', $order_id)
		];
	}
}
