<?php
namespace Opencart\Catalog\Model\Extension\Bookurier\Shipping;

class Bookurier extends \Opencart\System\Engine\Model {
	/**
	 * @param array<string, mixed> $address
	 *
	 * @return array<string, mixed>
	 */
	public function getQuote(array $address): array {
		$this->load->language('extension/bookurier/shipping/bookurier');
		$this->load->model('localisation/geo_zone');

		$status = $this->isEnabledForAddress($address);
		$method_data = [];

		if ($status) {
			$title = trim((string)$this->config->get('shipping_bookurier_title'));

			if (!$title) {
				$title = $this->language->get('text_description');
			}

			$cost = (float)$this->config->get('shipping_bookurier_cost');

			$quote_data['bookurier'] = [
				'code'         => 'bookurier.bookurier',
				'name'         => $title,
				'cost'         => $cost,
				'tax_class_id' => (int)$this->config->get('shipping_bookurier_tax_class_id'),
				'text'         => $this->currency->format($this->tax->calculate($cost, (int)$this->config->get('shipping_bookurier_tax_class_id'), (bool)$this->config->get('config_tax')), $this->session->data['currency'])
			];

			$method_data = [
				'code'       => 'bookurier',
				'name'       => $this->language->get('heading_title'),
				'quote'      => $quote_data,
				'sort_order' => (int)$this->config->get('shipping_bookurier_sort_order'),
				'error'      => false
			];
		}

		return $method_data;
	}

	/**
	 * @param array<string, mixed> $address
	 */
	private function isEnabledForAddress(array $address): bool {
		if (!$this->config->get('module_bookurier_status') || !$this->config->get('shipping_bookurier_status')) {
			return false;
		}

		$geo_zone_id = (int)$this->config->get('shipping_bookurier_geo_zone_id');

		if (!$geo_zone_id) {
			return true;
		}

		$result = $this->model_localisation_geo_zone->getGeoZone($geo_zone_id, (int)$address['country_id'], (int)$address['zone_id']);

		return (bool)$result;
	}
}
