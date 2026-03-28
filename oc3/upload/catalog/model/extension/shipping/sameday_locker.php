<?php
class ModelExtensionShippingSamedayLocker extends Model {
	public function getQuote($address) {
		$this->load->language('extension/shipping/sameday_locker');

		$method_data = [];

		if ($this->isEnabledForAddress($address)) {
			$title = trim((string)$this->config->get('shipping_sameday_locker_title'));

			if ($title === '') {
				$title = $this->language->get('text_description');
			}

			$cost = (float)$this->config->get('shipping_sameday_locker_cost');
			$quote_data = [];
			$quote_data['sameday_locker'] = [
				'code' => 'sameday_locker.sameday_locker',
				'title' => $title,
				'cost' => $cost,
				'tax_class_id' => (int)$this->config->get('shipping_sameday_locker_tax_class_id'),
				'text' => $this->currency->format($this->tax->calculate($cost, (int)$this->config->get('shipping_sameday_locker_tax_class_id'), (bool)$this->config->get('config_tax')), $this->session->data['currency'])
			];

			$method_data = [
				'code' => 'sameday_locker',
				'title' => $this->language->get('heading_title'),
				'quote' => $quote_data,
				'sort_order' => (int)$this->config->get('shipping_sameday_locker_sort_order'),
				'error' => false
			];
		}

		return $method_data;
	}

	private function isEnabledForAddress($address) {
		if (!$this->config->get('module_bookurier_status')
			|| !$this->config->get('module_bookurier_sameday_enabled')
			|| !$this->config->get('shipping_sameday_locker_status')) {
			return false;
		}

		$geo_zone_id = (int)$this->config->get('shipping_sameday_locker_geo_zone_id');

		if (!$geo_zone_id) {
			return true;
		}

		$result = $this->db->query("SELECT * FROM `" . DB_PREFIX . "zone_to_geo_zone` "
			. "WHERE `geo_zone_id` = '" . $geo_zone_id . "' "
			. "AND `country_id` = '" . (int)$address['country_id'] . "' "
			. "AND (`zone_id` = '" . (int)$address['zone_id'] . "' OR `zone_id` = '0')");

		return (bool)$result->num_rows;
	}
}
