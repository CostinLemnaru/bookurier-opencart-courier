<?php

if (!defined('BOOKURIER_SHARED_LIBRARY_ROOT')) {
	$candidates = [
		DIR_SYSTEM . 'library/extension/bookurier',
		'/workspace/modules/bookurier/system/library'
	];
	$shared_root = '';

	foreach ($candidates as $candidate) {
		if (is_dir($candidate) && is_file($candidate . '/settings.php')) {
			$shared_root = rtrim($candidate, '/');
			break;
		}
	}

	if ($shared_root === '') {
		throw new \RuntimeException('Bookurier shared library root could not be resolved.');
	}

	define('BOOKURIER_SHARED_LIBRARY_ROOT', $shared_root);

	$files = [
		'api_exception.php',
		'settings.php',
		'platform.php',
		'awb_repository.php',
		'bookurier_client.php',
		'bookurier_order_repository.php',
		'bookurier_payload_builder.php',
		'bookurier_awb_service.php',
		'sameday_client.php',
		'sameday_locker_repository.php',
		'sameday_locker_selection_repository.php',
		'sameday_locker_sync_service.php',
		'sameday_pickup_point_sync_service.php',
		'sameday_payload_builder.php',
		'sameday_awb_service.php',
		'sameday_locker_checkout_service.php',
		'order_awb_service.php'
	];

	foreach ($files as $file) {
		require_once BOOKURIER_SHARED_LIBRARY_ROOT . '/' . $file;
	}
}
