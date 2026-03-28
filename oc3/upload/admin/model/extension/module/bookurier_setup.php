<?php
require_once DIR_SYSTEM . 'library/extension/bookurier_bootstrap.php';

use Opencart\System\Library\Extension\Bookurier\Settings;

class ModelExtensionModuleBookurierSetup extends Model {
	public function installModule() {
		$this->installSchema();
		$this->installEvents();
		$this->installPermissions();

		$module_defaults = Settings::moduleDefaults();
		$module_defaults['module_bookurier_auto_awb_status_ids'] = $this->resolveDefaultAutoAwbStatusIds();

		$this->applyDefaultSettings(Settings::MODULE_SETTING_CODE, $module_defaults);
		$this->applyDefaultSettings(Settings::SHIPPING_BOOKURIER_CODE, Settings::shippingDefaults(Settings::SHIPPING_BOOKURIER_CODE));
		$this->applyDefaultSettings(Settings::SHIPPING_SAMEDAY_LOCKER_CODE, Settings::shippingDefaults(Settings::SHIPPING_SAMEDAY_LOCKER_CODE));
	}

	public function uninstallModule() {
		$this->uninstallEvents();
		$this->uninstallPermissions();

		if (!$this->hasInstalledShippingExtensions()) {
			$this->dropSchema();
		}
	}

	public function installShipping($code) {
		$this->installSchema();
		$this->installPermissions();

		$defaults = Settings::shippingDefaults($code);

		if ($defaults) {
			$this->applyDefaultSettings($code, $defaults);
		}
	}

	public function uninstallShipping() {
		if (!$this->isModuleInstalled() && !$this->hasInstalledShippingExtensions()) {
			$this->dropSchema();
		}
	}

	public function installSchema() {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . Settings::awbTable(DB_PREFIX) . "` (
			`bookurier_awb_id` INT(11) NOT NULL AUTO_INCREMENT,
			`order_id` INT(11) NOT NULL,
			`courier_code` VARCHAR(32) NOT NULL,
			`awb_code` VARCHAR(64) NOT NULL DEFAULT '',
			`locker_id` VARCHAR(64) NOT NULL DEFAULT '',
			`provider_status` VARCHAR(128) NOT NULL DEFAULT '',
			`panel_status` VARCHAR(32) NOT NULL DEFAULT '',
			`error_message` TEXT NULL,
			`request_payload` LONGTEXT NULL,
			`response_payload` LONGTEXT NULL,
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`bookurier_awb_id`),
			UNIQUE KEY `order_id` (`order_id`),
			KEY `courier_code` (`courier_code`),
			KEY `awb_code` (`awb_code`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . Settings::lockerTable(DB_PREFIX) . "` (
			`bookurier_sameday_locker_id` INT(11) NOT NULL AUTO_INCREMENT,
			`locker_id` VARCHAR(64) NOT NULL,
			`name` VARCHAR(191) NOT NULL DEFAULT '',
			`city` VARCHAR(128) NOT NULL DEFAULT '',
			`county` VARCHAR(128) NOT NULL DEFAULT '',
			`address` VARCHAR(255) NOT NULL DEFAULT '',
			`postal_code` VARCHAR(32) NOT NULL DEFAULT '',
			`latitude` DECIMAL(10,7) NULL DEFAULT NULL,
			`longitude` DECIMAL(10,7) NULL DEFAULT NULL,
			`box_count` INT(11) NOT NULL DEFAULT 0,
			`is_active` TINYINT(1) NOT NULL DEFAULT 1,
			`raw_payload` LONGTEXT NULL,
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`bookurier_sameday_locker_id`),
			UNIQUE KEY `locker_id` (`locker_id`),
			KEY `is_active` (`is_active`),
			KEY `city` (`city`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . Settings::lockerSelectionTable(DB_PREFIX) . "` (
			`bookurier_sameday_locker_selection_id` INT(11) NOT NULL AUTO_INCREMENT,
			`session_id` VARCHAR(128) NOT NULL DEFAULT '',
			`order_id` INT(11) NOT NULL DEFAULT 0,
			`quote_code` VARCHAR(64) NOT NULL DEFAULT '',
			`locker_id` VARCHAR(64) NOT NULL DEFAULT '',
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`bookurier_sameday_locker_selection_id`),
			UNIQUE KEY `session_id` (`session_id`),
			KEY `order_id` (`order_id`),
			KEY `locker_id` (`locker_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	}

	private function installEvents() {
		$this->load->model('setting/event');

		$this->model_setting_event->deleteEventByCode(Settings::EVENT_AUTO_AWB_CODE);
		$this->model_setting_event->deleteEventByCode(Settings::EVENT_ADMIN_ORDER_INFO_CODE);
		$this->model_setting_event->deleteEventByCode(Settings::EVENT_CHECKOUT_SHIPPING_METHOD_CODE);
		$this->model_setting_event->deleteEventByCode(Settings::EVENT_ORDER_BIND_LOCKER_CODE);

		$this->model_setting_event->addEvent(Settings::EVENT_AUTO_AWB_CODE, 'catalog/model/checkout/order/addOrderHistory/after', 'extension/module/bookurier/event/autoAwb', 1, 0);
		$this->model_setting_event->addEvent(Settings::EVENT_ADMIN_ORDER_INFO_CODE, 'admin/view/sale/order_info/before', 'extension/module/bookurier/event/orderInfoBefore', 1, 0);
		$this->model_setting_event->addEvent(Settings::EVENT_CHECKOUT_SHIPPING_METHOD_CODE, 'catalog/view/checkout/shipping_method/after', 'extension/module/bookurier/event/shippingMethodAfter', 1, 0);
		$this->model_setting_event->addEvent(Settings::EVENT_ORDER_BIND_LOCKER_CODE, 'catalog/model/checkout/order/addOrder/after', 'extension/module/bookurier/event/orderAddAfter', 1, 0);
	}

	private function uninstallEvents() {
		$this->load->model('setting/event');

		$this->model_setting_event->deleteEventByCode(Settings::EVENT_AUTO_AWB_CODE);
		$this->model_setting_event->deleteEventByCode(Settings::EVENT_ADMIN_ORDER_INFO_CODE);
		$this->model_setting_event->deleteEventByCode(Settings::EVENT_CHECKOUT_SHIPPING_METHOD_CODE);
		$this->model_setting_event->deleteEventByCode(Settings::EVENT_ORDER_BIND_LOCKER_CODE);
	}

	private function installPermissions() {
		$this->load->model('user/user_group');

		$routes = [
			'extension/module/bookurier',
			'extension/shipping/bookurier',
			'extension/shipping/sameday_locker',
			'extension/module/bookurier/awb'
		];

		foreach ($routes as $route) {
			foreach (['access', 'modify'] as $type) {
				$this->model_user_user_group->addPermission($this->user->getGroupId(), $type, $route);
			}
		}
	}

	private function uninstallPermissions() {
		$this->load->model('user/user_group');

		$routes = [
			'extension/module/bookurier',
			'extension/shipping/bookurier',
			'extension/shipping/sameday_locker',
			'extension/module/bookurier/awb'
		];

		foreach ($routes as $route) {
			foreach (['access', 'modify'] as $type) {
				$this->model_user_user_group->removePermission($this->user->getGroupId(), $type, $route);
			}
		}
	}

	private function applyDefaultSettings($code, array $defaults) {
		$this->load->model('setting/setting');

		$current = $this->model_setting_setting->getSetting($code);
		$this->model_setting_setting->editSetting($code, $current + $defaults);
	}

	private function dropSchema() {
		$this->db->query("DROP TABLE IF EXISTS `" . Settings::lockerSelectionTable(DB_PREFIX) . "`");
		$this->db->query("DROP TABLE IF EXISTS `" . Settings::lockerTable(DB_PREFIX) . "`");
		$this->db->query("DROP TABLE IF EXISTS `" . Settings::awbTable(DB_PREFIX) . "`");
	}

	private function resolveDefaultAutoAwbStatusIds() {
		$query = $this->db->query("SELECT `order_status_id`, `name` FROM `" . DB_PREFIX . "order_status` ORDER BY `language_id` ASC, `order_status_id` ASC");
		$preferred = ['processing', 'processed', 'shipped'];
		$lookup = [];

		foreach ($query->rows as $row) {
			$name = strtolower(trim((string)$row['name']));

			if ($name !== '' && !isset($lookup[$name])) {
				$lookup[$name] = (int)$row['order_status_id'];
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

	private function hasInstalledShippingExtensions() {
		$this->load->model('setting/extension');

		return (bool)$this->model_setting_extension->getExtensionByCode('shipping', 'bookurier')
			|| (bool)$this->model_setting_extension->getExtensionByCode('shipping', 'sameday_locker');
	}

	private function isModuleInstalled() {
		$this->load->model('setting/extension');

		return (bool)$this->model_setting_extension->getExtensionByCode('module', 'bookurier');
	}
}
