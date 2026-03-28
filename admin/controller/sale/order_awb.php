<?php
namespace Opencart\Admin\Controller\Extension\Bookurier\Sale;

use Opencart\System\Library\Extension\Bookurier\OrderAwbService;
use Opencart\System\Library\Extension\Bookurier\Settings;

class OrderAwb extends \Opencart\System\Engine\Controller {
	public function tab(int $order_id = 0): string {
		return $this->renderPanel($order_id);
	}

	public function panel(): void {
		$this->response->setOutput($this->renderPanel($this->getOrderId()));
	}

	public function generate(): void {
		$this->load->language('extension/bookurier/sale/order_awb');

		$json = [];

		if (!$this->user->hasPermission('modify', Settings::ADMIN_AWB_ROUTE)) {
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

	public function refresh(): void {
		$this->load->language('extension/bookurier/sale/order_awb');

		$json = [];

		if (!$this->user->hasPermission('modify', Settings::ADMIN_AWB_ROUTE)) {
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

	public function download(): void {
		$this->load->language('extension/bookurier/sale/order_awb');

		if (!$this->user->hasPermission('access', Settings::ADMIN_AWB_ROUTE)) {
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

	private function renderPanel(int $order_id): string {
		$this->load->language('extension/bookurier/sale/order_awb');

		$data = [
			'error' => ''
		];

		if (!$this->user->hasPermission('access', Settings::ADMIN_AWB_ROUTE)) {
			$data['error'] = $this->language->get('error_permission');

			return $this->load->view('extension/bookurier/sale/order_awb', $data);
		}

		if ($order_id <= 0) {
			$data['error'] = $this->language->get('error_order_id');

			return $this->load->view('extension/bookurier/sale/order_awb', $data);
		}

		try {
			$service = new OrderAwbService($this->registry);
			$context = $service->getOrderContext($order_id);

			$data = array_merge($data, $context);
			$data['panel_url'] = $this->url->link('extension/bookurier/sale/order_awb.panel', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . $order_id);
			$data['generate_url'] = $this->url->link('extension/bookurier/sale/order_awb.generate', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . $order_id);
			$data['refresh_url'] = $this->url->link('extension/bookurier/sale/order_awb.refresh', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . $order_id);
			$data['download_url'] = $this->url->link('extension/bookurier/sale/order_awb.download', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . $order_id);
			$data['panel_status_label'] = $this->resolvePanelStatusLabel((string)$data['awb']['panel_status']);
		} catch (\Throwable $exception) {
			$data['error'] = $exception->getMessage();
		}

		return $this->load->view('extension/bookurier/sale/order_awb', $data);
	}

	private function getOrderId(): int {
		if (isset($this->request->post['order_id'])) {
			return (int)$this->request->post['order_id'];
		}

		if (isset($this->request->get['order_id'])) {
			return (int)$this->request->get['order_id'];
		}

		return 0;
	}

	/**
	 * @param array<string, mixed> $json
	 */
	private function jsonResponse(array $json): void {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	private function resolvePanelStatusLabel(string $panel_status): string {
		switch ($panel_status) {
			case Settings::PANEL_STATUS_GENERATED:
				return $this->language->get('text_panel_status_generated');
			case Settings::PANEL_STATUS_PENDING:
				return $this->language->get('text_panel_status_pending');
			case Settings::PANEL_STATUS_ERROR:
				return $this->language->get('text_panel_status_error');
			default:
				return $this->language->get('text_none');
		}
	}
}
