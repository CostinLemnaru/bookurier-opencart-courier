<?php
namespace Opencart\System\Library\Extension\Bookurier;

/**
 * Small cross-version helpers for OpenCart 3 and 4 runtime differences.
 */
class Platform {
	public static function createLog(string $filename): object {
		if (class_exists('\Opencart\System\Library\Log')) {
			return new \Opencart\System\Library\Log($filename);
		}

		if (class_exists('\Log')) {
			return new \Log($filename);
		}

		throw new \RuntimeException('OpenCart log class is not available.');
	}

	public static function createWeight(object $registry): object {
		if (class_exists('\Opencart\System\Library\Cart\Weight')) {
			return new \Opencart\System\Library\Cart\Weight($registry);
		}

		if (class_exists('\Cart\Weight')) {
			return new \Cart\Weight($registry);
		}

		throw new \RuntimeException('OpenCart weight library is not available.');
	}

	public static function sessionId(object $session): string {
		if (method_exists($session, 'getId')) {
			return (string)$session->getId();
		}

		return trim((string)($session->session_id ?? ''));
	}
}
