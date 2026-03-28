<?php
namespace Opencart\System\Library\Extension\Bookurier;

class SamedayLockerCheckoutService {
	private object $config;
	private object $db;
	private object $session;
	private SamedayLockerRepository $locker_repository;
	private SamedayLockerSelectionRepository $selection_repository;

	public function __construct(object $registry) {
		$this->config = $registry->get('config');
		$this->db = $registry->get('db');
		$this->session = $registry->get('session');
		$this->locker_repository = new SamedayLockerRepository($this->db);
		$this->selection_repository = new SamedayLockerSelectionRepository($this->db);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getModalData(): array {
		$this->assertEnabled();

		$lockers = $this->locker_repository->getActiveForCheckout();

		if (!$lockers) {
			throw new \RuntimeException('No SameDay lockers are available. Please sync lockers first.');
		}

		$selected_locker_id = $this->selection_repository->getLockerIdBySessionId(Platform::sessionId($this->session));

		return [
			'lockers'            => $lockers,
			'selected_locker_id' => $selected_locker_id,
			'selected_locker'    => $this->findLockerInList($lockers, $selected_locker_id)
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function saveSelection(string $quote_code, string $locker_id): array {
		$this->assertEnabled();

		if (!$this->isSamedayLockerQuote($quote_code)) {
			throw new \RuntimeException('Selected shipping method is not SameDay Locker.');
		}

		if (!$this->locker_repository->isActiveLockerId($locker_id)) {
			throw new \RuntimeException('Selected locker is no longer available.');
		}

		$this->selection_repository->saveForSession(Platform::sessionId($this->session), $quote_code, $locker_id);

		return $this->locker_repository->findActiveLockerById($locker_id) ?? [];
	}

	/**
	 * @param array<string, mixed> $order_data
	 */
	public function bindSelectionToOrder(int $order_id, array $order_data = []): void {
		if ($order_id <= 0) {
			return;
		}

		$quote_code = $this->resolveQuoteCode($order_data);

		if (!$this->isSamedayLockerQuote($quote_code)) {
			return;
		}

		$this->selection_repository->bindSessionToOrder(Platform::sessionId($this->session), $order_id);
	}

	public function isSamedayLockerQuote(string $quote_code): bool {
		$quote_code = strtolower(trim($quote_code));

		return $quote_code !== '' && strpos($quote_code, 'sameday_locker.') === 0;
	}

	private function assertEnabled(): void {
		if (!(int)$this->config->get('module_bookurier_status') || !(int)$this->config->get('module_bookurier_sameday_enabled')) {
			throw new \RuntimeException('SameDay Locker is disabled.');
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $lockers
	 *
	 * @return array<string, mixed>
	 */
	private function findLockerInList(array $lockers, string $locker_id): array {
		$locker_id = trim($locker_id);

		if ($locker_id === '') {
			return [];
		}

		foreach ($lockers as $locker) {
			if ((string)($locker['locker_id'] ?? '') === $locker_id) {
				return $locker;
			}
		}

		return [];
	}

	/**
	 * @param array<string, mixed> $order_data
	 */
	private function resolveQuoteCode(array $order_data): string {
		$shipping_code = trim((string)($order_data['shipping_code'] ?? ''));

		if ($shipping_code !== '') {
			return $shipping_code;
		}

		$shipping_method = $order_data['shipping_method'] ?? [];

		if (is_array($shipping_method) && !empty($shipping_method['code'])) {
			return (string)$shipping_method['code'];
		}

		if (is_string($shipping_method) && trim($shipping_method) !== '') {
			return trim($shipping_method);
		}

		return '';
	}
}
