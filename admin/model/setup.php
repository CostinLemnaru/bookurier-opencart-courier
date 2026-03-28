<?php
namespace Opencart\Admin\Model\Extension\Bookurier;

use Opencart\System\Library\Extension\Bookurier\Settings;

/**
 * Installs shared schema and default settings for the Bookurier extension.
 */
class Setup extends \Opencart\System\Engine\Model {
	public function installModule(): void {
		$this->installSchema();
		$this->installEvents();
		$this->installPermissions();

		$module_defaults = Settings::moduleDefaults();
		$module_defaults['module_bookurier_auto_awb_status_ids'] = $this->resolveDefaultAutoAwbStatusIds();

		$this->applyDefaultSettings(Settings::MODULE_SETTING_CODE, $module_defaults);
		$this->applyDefaultSettings(Settings::SHIPPING_BOOKURIER_CODE, Settings::shippingDefaults(Settings::SHIPPING_BOOKURIER_CODE));
		$this->applyDefaultSettings(Settings::SHIPPING_SAMEDAY_LOCKER_CODE, Settings::shippingDefaults(Settings::SHIPPING_SAMEDAY_LOCKER_CODE));
	}

	public function uninstallModule(): void {
		$this->uninstallEvents();
		$this->uninstallPermissions();

		if (!$this->hasInstalledShippingExtensions()) {
			$this->dropSchema();
		}
	}

	public function installShipping(string $code): void {
		$this->installSchema();

		$defaults = Settings::shippingDefaults($code);

		if ($defaults) {
			$this->applyDefaultSettings($code, $defaults);
		}
	}

	public function uninstallShipping(): void {
		if (!$this->isModuleInstalled() && !$this->hasInstalledShippingExtensions()) {
			$this->dropSchema();
		}
	}

	public function installSchema(): void {
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

	private function applyDefaultSettings(string $code, array $defaults): void {
		$this->load->model('setting/setting');

		$current = $this->model_setting_setting->getSetting($code);

		$this->model_setting_setting->editSetting($code, $current + $defaults);
	}

	private function dropSchema(): void {
		$this->db->query("DROP TABLE IF EXISTS `" . Settings::lockerSelectionTable(DB_PREFIX) . "`");
		$this->db->query("DROP TABLE IF EXISTS `" . Settings::lockerTable(DB_PREFIX) . "`");
		$this->db->query("DROP TABLE IF EXISTS `" . Settings::awbTable(DB_PREFIX) . "`");
	}

	private function installEvents(): void {
		$this->load->model('setting/event');

		$this->model_setting_event->deleteEventByCode(Settings::EVENT_AUTO_AWB_CODE);
		$this->model_setting_event->deleteEventByCode(Settings::EVENT_ADMIN_ORDER_INFO_CODE);
		$this->model_setting_event->deleteEventByCode(Settings::EVENT_CHECKOUT_SHIPPING_METHOD_CODE);
		$this->model_setting_event->deleteEventByCode(Settings::EVENT_ORDER_BIND_LOCKER_CODE);

		$this->model_setting_event->addEvent([
			'code'        => Settings::EVENT_AUTO_AWB_CODE,
			'description' => 'Bookurier auto AWB generation on order history updates',
			'trigger'     => 'catalog/model/checkout/order.addHistory/after',
			'action'      => Settings::CATALOG_EVENT_ROUTE . '.autoAwb',
			'status'      => 1,
			'sort_order'  => 0
		]);

		$this->model_setting_event->addEvent([
			'code'        => Settings::EVENT_ADMIN_ORDER_INFO_CODE,
			'description' => 'Bookurier admin order AWB tab',
			'trigger'     => 'admin/view/sale/order_info/before',
			'action'      => Settings::ADMIN_EVENT_ROUTE . '.orderInfoBefore',
			'status'      => 1,
			'sort_order'  => 0
		]);

		$this->model_setting_event->addEvent([
			'code'        => Settings::EVENT_CHECKOUT_SHIPPING_METHOD_CODE,
			'description' => 'Bookurier SameDay locker selector in checkout shipping modal',
			'trigger'     => 'catalog/view/checkout/shipping_method/after',
			'action'      => Settings::CATALOG_EVENT_ROUTE . '.shippingMethodAfter',
			'status'      => 1,
			'sort_order'  => 0
		]);

		$this->model_setting_event->addEvent([
			'code'        => Settings::EVENT_ORDER_BIND_LOCKER_CODE,
			'description' => 'Bookurier bind SameDay locker selection to order',
			'trigger'     => 'catalog/model/checkout/order.addOrder/after',
			'action'      => Settings::CATALOG_EVENT_ROUTE . '.orderAddAfter',
			'status'      => 1,
			'sort_order'  => 0
		]);
	}

	private function uninstallEvents(): void {
		$this->load->model('setting/event');

		$this->model_setting_event->deleteEventByCode(Settings::EVENT_AUTO_AWB_CODE);
		$this->model_setting_event->deleteEventByCode(Settings::EVENT_ADMIN_ORDER_INFO_CODE);
		$this->model_setting_event->deleteEventByCode(Settings::EVENT_CHECKOUT_SHIPPING_METHOD_CODE);
		$this->model_setting_event->deleteEventByCode(Settings::EVENT_ORDER_BIND_LOCKER_CODE);
	}

	private function installPermissions(): void {
		$this->load->model('user/user_group');

		foreach (['access', 'modify'] as $type) {
			$this->model_user_user_group->addPermission($this->user->getGroupId(), $type, Settings::ADMIN_AWB_ROUTE);
		}
	}

	private function uninstallPermissions(): void {
		$this->load->model('user/user_group');

		foreach (['access', 'modify'] as $type) {
			$this->model_user_user_group->removePermission($this->user->getGroupId(), $type, Settings::ADMIN_AWB_ROUTE);
		}
	}

	/**
	 * @return array<int, int>
	 */
	private function resolveDefaultAutoAwbStatusIds(): array {
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

	private function hasInstalledShippingExtensions(): bool {
		$this->load->model('setting/extension');

		return (bool)$this->model_setting_extension->getExtensionByCode('shipping', 'bookurier')
			|| (bool)$this->model_setting_extension->getExtensionByCode('shipping', 'sameday_locker');
	}

	private function isModuleInstalled(): bool {
		$this->load->model('setting/extension');

		return (bool)$this->model_setting_extension->getExtensionByCode('module', 'bookurier');
	}
}
