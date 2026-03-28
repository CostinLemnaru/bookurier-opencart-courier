<?php
require_once DIR_SYSTEM . 'library/extension/bookurier_bootstrap.php';

use Opencart\System\Library\Extension\Bookurier\OrderAwbService;

class ControllerExtensionModuleBookurierAwb extends Controller {
	public function tab($order_id = 0) {
		return $this->renderPanel((int)$order_id);
	}

	public function panel() {
		$this->response->setOutput($this->renderPanel($this->getOrderId()));
	}

	public function generate() {
		$this->load->language('extension/module/bookurier/awb');
		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/module/bookurier/awb')) {
			$json['error'] = $this->language->get('error_permission');
		}

		$order_id = $this->getOrderId();

		if (!$json && $order_id <= 0) {
			$json['error'] = $this->language->get('error_order_id');
		}

		if (!$json) {
			try {
				$service = new OrderAwbService($this->registry);
				$awb = $service->generateForOrder($order_id);
				$json['success'] = sprintf($this->language->get('text_generate_success'), $awb['awb_code']);
			} catch (\Throwable $exception) {
				$json['error'] = $exception->getMessage();
			}
		}

		$this->jsonResponse($json);
	}

	public function refresh() {
		$this->load->language('extension/module/bookurier/awb');
		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/module/bookurier/awb')) {
			$json['error'] = $this->language->get('error_permission');
		}

		$order_id = $this->getOrderId();

		if (!$json && $order_id <= 0) {
			$json['error'] = $this->language->get('error_order_id');
		}

		if (!$json) {
			try {
				$service = new OrderAwbService($this->registry);
				$awb = $service->refreshStatusForOrder($order_id);
				$json['success'] = sprintf($this->language->get('text_refresh_success'), $awb['provider_status']);
			} catch (\Throwable $exception) {
				$json['error'] = $exception->getMessage();
			}
		}

		$this->jsonResponse($json);
	}

	public function download() {
		$this->load->language('extension/module/bookurier/awb');

		if (!$this->user->hasPermission('access', 'extension/module/bookurier/awb')) {
			$this->response->addHeader('Content-Type: text/plain; charset=utf-8');
			$this->response->setOutput($this->language->get('error_permission'));

			return;
		}

		$order_id = $this->getOrderId();

		if ($order_id <= 0) {
			$this->response->addHeader('Content-Type: text/plain; charset=utf-8');
			$this->response->setOutput($this->language->get('error_order_id'));

			return;
		}

		try {
			$service = new OrderAwbService($this->registry);
			$file = $service->downloadLabelForOrder($order_id);

			$this->response->addHeader('Content-Type: ' . $file['content_type']);
			$this->response->addHeader('Content-Disposition: attachment; filename="' . basename($file['filename']) . '"');
			$this->response->setOutput($file['content']);
		} catch (\Throwable $exception) {
			$this->response->addHeader('Content-Type: text/plain; charset=utf-8');
			$this->response->setOutput($exception->getMessage());
		}
	}

	private function renderPanel($order_id) {
		$this->load->language('extension/module/bookurier/awb');
		$data = ['error' => ''];

		if (!$this->user->hasPermission('access', 'extension/module/bookurier/awb')) {
			$data['error'] = $this->language->get('error_permission');

			return $this->load->view('extension/module/bookurier/awb', $data);
		}

		if ((int)$order_id <= 0) {
			$data['error'] = $this->language->get('error_order_id');

			return $this->load->view('extension/module/bookurier/awb', $data);
		}

		try {
			$service = new OrderAwbService($this->registry);
			$context = $service->getOrderContext((int)$order_id);

			$data = array_merge($data, $context);
			$data['panel_url'] = $this->url->link('extension/module/bookurier/awb/panel', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . (int)$order_id, true);
			$data['generate_url'] = $this->url->link('extension/module/bookurier/awb/generate', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . (int)$order_id, true);
			$data['refresh_url'] = $this->url->link('extension/module/bookurier/awb/refresh', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . (int)$order_id, true);
			$data['download_url'] = $this->url->link('extension/module/bookurier/awb/download', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . (int)$order_id, true);
			$data['panel_status_label'] = $this->resolvePanelStatusLabel((string)$data['awb']['panel_status']);
		} catch (\Throwable $exception) {
			$data['error'] = $exception->getMessage();
		}

		return $this->load->view('extension/module/bookurier/awb', $data);
	}

	private function getOrderId() {
		if (isset($this->request->post['order_id'])) {
			return (int)$this->request->post['order_id'];
		}

		if (isset($this->request->get['order_id'])) {
			return (int)$this->request->get['order_id'];
		}

		return 0;
	}

	private function resolvePanelStatusLabel($panel_status) {
		switch ($panel_status) {
			case 'generated':
				return $this->language->get('text_panel_status_generated');
			case 'pending':
				return $this->language->get('text_panel_status_pending');
			case 'error':
				return $this->language->get('text_panel_status_error');
			default:
				return $this->language->get('text_none');
		}
	}

	private function jsonResponse(array $json) {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}
}
