<?php
namespace Opencart\System\Library\Extension\Bookurier;

class SamedayLockerSyncService {
	private SamedayLockerRepository $locker_repository;

	public function __construct(object $db) {
		$this->locker_repository = new SamedayLockerRepository($db);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function sync(string $username, string $password, string $environment): array {
		$client = new SamedayClient($username, $password, $environment);
		$client->authenticate(true);

		$page = 1;
		$per_page = 500;
		$max_pages = 200;
		$lockers = [];

		do {
			$batch = $client->getLockers($page, $per_page);

			foreach ($batch as $locker) {
				if (is_array($locker)) {
					$lockers[] = $locker;
				}
			}

			$page++;
		} while ($batch && count($batch) === $per_page && $page <= $max_pages);

		if (!$lockers) {
			throw new \RuntimeException('No lockers were returned by SameDay.');
		}

		return [
			'count'     => $this->locker_repository->upsertMany($lockers),
			'synced_at' => date('Y-m-d H:i:s')
		];
	}
}
