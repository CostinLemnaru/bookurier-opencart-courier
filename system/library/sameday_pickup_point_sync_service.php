<?php
namespace Opencart\System\Library\Extension\Bookurier;

class SamedayPickupPointSyncService {
	/**
	 * @return array<string, mixed>
	 */
	public function sync(string $username, string $password, string $environment, int $preferred_pickup_point = 0): array {
		$client = new SamedayClient($username, $password, $environment);
		$client->authenticate(true);

		$page = 1;
		$per_page = 500;
		$max_pages = 100;
		$pickup_points = [];

		do {
			$batch = $client->getPickupPoints($page, $per_page);

			foreach ($batch as $pickup_point) {
				$normalized = $this->normalizePickupPoint($pickup_point);

				if ($normalized !== null) {
					$pickup_points[] = $normalized;
				}
			}

			$page++;
		} while ($batch && count($batch) === $per_page && $page <= $max_pages);

		if (!$pickup_points) {
			throw new \RuntimeException('No pickup points were returned by SameDay.');
		}

		return [
			'pickup_points' => $pickup_points,
			'selected_id'   => $this->resolveSelectedPickupPointId($pickup_points, $preferred_pickup_point),
			'count'         => count($pickup_points),
			'synced_at'     => date('Y-m-d H:i:s')
		];
	}

	/**
	 * @param array<string, mixed> $pickup_point
	 *
	 * @return array<string, mixed>|null
	 */
	private function normalizePickupPoint(array $pickup_point): ?array {
		$id = (int)($pickup_point['id'] ?? 0);
		$is_active = !isset($pickup_point['status']) || !empty($pickup_point['status']);

		if ($id <= 0 || !$is_active) {
			return null;
		}

		$alias = trim((string)($pickup_point['alias'] ?? ''));
		$address = trim((string)($pickup_point['address'] ?? ''));
		$suffix = trim($alias . ' - ' . $address, ' -');

		return [
			'id'      => $id,
			'name'    => '[' . $id . '] ' . ($suffix !== '' ? $suffix : 'Pickup Point'),
			'default' => !empty($pickup_point['defaultPickupPoint'])
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $pickup_points
	 */
	private function resolveSelectedPickupPointId(array $pickup_points, int $preferred_pickup_point): int {
		if ($preferred_pickup_point > 0) {
			foreach ($pickup_points as $pickup_point) {
				if ((int)($pickup_point['id'] ?? 0) === $preferred_pickup_point) {
					return $preferred_pickup_point;
				}
			}
		}

		foreach ($pickup_points as $pickup_point) {
			if (!empty($pickup_point['default'])) {
				return (int)($pickup_point['id'] ?? 0);
			}
		}

		return (int)($pickup_points[0]['id'] ?? 0);
	}
}
