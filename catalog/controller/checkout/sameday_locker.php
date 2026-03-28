<?php
namespace Opencart\Catalog\Controller\Extension\Bookurier\Checkout;

use Opencart\System\Library\Extension\Bookurier\SamedayLockerCheckoutService;

class SamedayLocker extends \Opencart\System\Engine\Controller {
	public function options(): void {
		$this->load->language('extension/bookurier/checkout/sameday_locker');

		$json = [];

		try {
			$service = new SamedayLockerCheckoutService($this->registry);
			$data = $service->getModalData();

			$json['lockers'] = $data['lockers'];
			$json['selected_locker_id'] = (string)($data['selected_locker_id'] ?? '');
		} catch (\Throwable $exception) {
			$json['error'] = $exception->getMessage();
		}

		$this->jsonResponse($json);
	}

	public function save(): void {
		$this->load->language('extension/bookurier/checkout/sameday_locker');

		$json = [];
		$quote_code = trim((string)($this->request->post['quote_code'] ?? ''));
		$locker_id = trim((string)($this->request->post['locker_id'] ?? ''));

		if ($quote_code === '' || $locker_id === '') {
			$json['error'] = $this->language->get('error_locker_required');
		}

		if (!$json) {
			try {
				$service = new SamedayLockerCheckoutService($this->registry);
				$locker = $service->saveSelection($quote_code, $locker_id);

				$json['success'] = $this->language->get('text_locker_saved');
				$json['locker_id'] = (string)($locker['locker_id'] ?? $locker_id);
				$json['locker_label'] = $this->buildLockerLabel($locker);
			} catch (\Throwable $exception) {
				$json['error'] = $exception->getMessage();
			}
		}

		$this->jsonResponse($json);
	}

	/**
	 * @param array<string, mixed> $locker
	 */
	private function buildLockerLabel(array $locker): string {
		$name = trim((string)($locker['name'] ?? ''));
		$city = trim((string)($locker['city'] ?? ''));
		$county = trim((string)($locker['county'] ?? ''));
		$postal_code = trim((string)($locker['postal_code'] ?? ''));
		$address = trim((string)($locker['address'] ?? ''));
		$details = trim($city
			. ($county !== '' ? ', ' . $county : '')
			. ($postal_code !== '' ? ' ' . $postal_code : '')
			. ($address !== '' ? ' - ' . $address : ''), ' -');

		return $details !== '' ? $name . ' (' . $details . ')' : $name;
	}

	/**
	 * @param array<string, mixed> $json
	 */
	private function jsonResponse(array $json): void {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}
}
