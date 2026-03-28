<?php
namespace Opencart\System\Library\Extension\Bookurier;

/**
 * Shared extension settings and schema names.
 */
class Settings {
	public const MODULE_SETTING_CODE = 'module_bookurier';
	public const SHIPPING_BOOKURIER_CODE = 'shipping_bookurier';
	public const SHIPPING_SAMEDAY_LOCKER_CODE = 'shipping_sameday_locker';
	public const ADMIN_AWB_ROUTE = 'extension/bookurier/sale/order_awb';
	public const ADMIN_EVENT_ROUTE = 'extension/bookurier/event/bookurier';
	public const CATALOG_EVENT_ROUTE = 'extension/bookurier/event/bookurier';
	public const EVENT_AUTO_AWB_CODE = 'bookurier_auto_awb';
	public const EVENT_ADMIN_ORDER_INFO_CODE = 'bookurier_admin_order_info';
	public const EVENT_CHECKOUT_SHIPPING_METHOD_CODE = 'bookurier_checkout_shipping_method';
	public const EVENT_ORDER_BIND_LOCKER_CODE = 'bookurier_order_bind_locker';
	public const COURIER_BOOKURIER = 'bookurier';
	public const COURIER_SAMEDAY_LOCKER = 'sameday_locker';
	public const PANEL_STATUS_ERROR = 'error';
	public const PANEL_STATUS_GENERATED = 'generated';
	public const PANEL_STATUS_PENDING = 'pending';
	public const BOOKURIER_API_BASE_URL = 'https://portal.bookurier.ro/api/';
	public const SAMEDAY_ENV_PROD = 'prod';
	public const SAMEDAY_ENV_DEMO = 'demo';
	public const SAMEDAY_API_BASE_URL_PROD = 'https://api.sameday.ro';
	public const SAMEDAY_API_BASE_URL_DEMO = 'https://sameday-api.demo.zitec.com';
	public const LOG_FILE = 'bookurier_courier.log';
	public const API_LOG_FILE = 'bookurier_api.log';

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function bookurierServiceOptions(): array {
		return [
			['id' => 1, 'name' => 'Bucuresti 24h (1)'],
			['id' => 3, 'name' => 'Metropolitan (3)'],
			['id' => 5, 'name' => 'Ilfov Extins (5)'],
			['id' => 7, 'name' => 'Bucuresti Today (7)'],
			['id' => 8, 'name' => 'National Economic (8)'],
			['id' => 9, 'name' => 'National 24 (9)'],
			['id' => 11, 'name' => 'National Premium (11)']
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function moduleDefaults(): array {
		return [
			'module_bookurier_status'                    => 1,
			'module_bookurier_bookurier_username'        => '',
			'module_bookurier_bookurier_password'        => '',
			'module_bookurier_bookurier_api_key'         => '',
			'module_bookurier_bookurier_pickup_point'    => '0',
			'module_bookurier_bookurier_service'         => '9',
			'module_bookurier_sameday_enabled'           => 0,
			'module_bookurier_sameday_environment'       => 'prod',
			'module_bookurier_sameday_username'          => '',
			'module_bookurier_sameday_password'          => '',
			'module_bookurier_sameday_pickup_point'      => '0',
			'module_bookurier_sameday_package_type'      => '0',
			'module_bookurier_sameday_services_cache'    => '{}',
			'module_bookurier_sameday_pickup_points_cache' => '[]',
			'module_bookurier_sameday_pickup_points_synced_at' => '',
			'module_bookurier_sameday_lockers_synced_at' => '',
			'module_bookurier_sameday_lockers_count'     => '0',
			'module_bookurier_auto_awb_enabled'          => 1,
			'module_bookurier_auto_awb_status_ids'       => []
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function shippingDefaults(string $code): array {
		if ($code === self::SHIPPING_BOOKURIER_CODE) {
			return [
				'shipping_bookurier_title'        => 'Bookurier',
				'shipping_bookurier_cost'         => '0.00',
				'shipping_bookurier_tax_class_id' => 0,
				'shipping_bookurier_geo_zone_id'  => 0,
				'shipping_bookurier_status'       => 1,
				'shipping_bookurier_sort_order'   => 1
			];
		}

		if ($code === self::SHIPPING_SAMEDAY_LOCKER_CODE) {
			return [
				'shipping_sameday_locker_title'        => 'Sameday Locker',
				'shipping_sameday_locker_cost'         => '0.00',
				'shipping_sameday_locker_tax_class_id' => 0,
				'shipping_sameday_locker_geo_zone_id'  => 0,
				'shipping_sameday_locker_status'       => 1,
				'shipping_sameday_locker_sort_order'   => 2
			];
		}

		return [];
	}

	public static function awbTable(string $db_prefix): string {
		return $db_prefix . 'bookurier_awb';
	}

	public static function lockerTable(string $db_prefix): string {
		return $db_prefix . 'bookurier_sameday_locker';
	}

	public static function lockerSelectionTable(string $db_prefix): string {
		return $db_prefix . 'bookurier_sameday_locker_selection';
	}

	public static function samedayBaseUrl(string $environment): string {
		return strtolower($environment) === self::SAMEDAY_ENV_DEMO
			? self::SAMEDAY_API_BASE_URL_DEMO
			: self::SAMEDAY_API_BASE_URL_PROD;
	}

	/**
	 * @return array<int, string>
	 */
	public static function internalModuleSettingKeys(): array {
		return [
			'module_bookurier_sameday_pickup_points_cache',
			'module_bookurier_sameday_pickup_points_synced_at',
			'module_bookurier_sameday_lockers_synced_at',
			'module_bookurier_sameday_lockers_count',
			'module_bookurier_sameday_services_cache'
		];
	}
}
