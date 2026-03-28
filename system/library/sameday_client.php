<?php
namespace Opencart\System\Library\Extension\Bookurier;

class SamedayClient {
	private string $username;
	private string $password;
	private string $environment;
	private string $base_url;
	private string $token = '';
	private string $token_expire_at = '';
	private object $api_logger;

	public function __construct(string $username = '', string $password = '', string $environment = Settings::SAMEDAY_ENV_PROD) {
		$this->username = trim($username);
		$this->password = trim($password);
		$this->environment = strtolower($environment) === Settings::SAMEDAY_ENV_DEMO ? Settings::SAMEDAY_ENV_DEMO : Settings::SAMEDAY_ENV_PROD;
		$this->base_url = rtrim(Settings::samedayBaseUrl($this->environment), '/');
		$this->api_logger = Platform::createLog(Settings::API_LOG_FILE);
	}

	public function isConfigured(): bool {
		return $this->username !== '' && $this->password !== '';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function authenticate(bool $remember_me = true): array {
		$this->assertConfigured();

		if ($this->hasValidToken()) {
			return [
				'token'     => $this->token,
				'expire_at' => $this->token_expire_at
			];
		}

		$response = $this->request('POST', '/api/authenticate', [
			'headers'     => [
				'X-AUTH-USERNAME' => $this->username,
				'X-AUTH-PASSWORD' => $this->password
			],
			'form_params' => [
				'remember_me' => $remember_me ? 1 : 0
			]
		], true, [
			'provider_code' => Settings::COURIER_SAMEDAY_LOCKER,
			'action'        => 'authenticate'
		]);

		try {
			$data = $this->decodeJsonResponse($response['body'], '/api/authenticate');

			if (trim((string)($data['token'] ?? '')) === '') {
				throw new ApiException($this->extractApiMessage($data, 'SameDay authentication did not return a token.'));
			}

			$this->token = trim((string)$data['token']);
			$this->token_expire_at = trim((string)($data['expire_at'] ?? ''));

			return $data;
		} catch (ApiException $exception) {
			$this->logApiError((string)($response['request_id'] ?? ''), (string)($response['action'] ?? 'authenticate'), 0, $exception->getMessage(), (string)($response['body'] ?? ''));

			throw $exception;
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getPickupPoints(int $page = 1, int $per_page = 500): array {
		$response = $this->requestWithToken('GET', '/api/client/pickup-points', [
			'query' => $this->buildPaginationQuery($page, $per_page)
		], [
			'provider_code' => Settings::COURIER_SAMEDAY_LOCKER,
			'action'        => 'get_pickup_points'
		]);

		return $this->extractDataList($response, '/api/client/pickup-points');
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getLockers(int $page = 1, int $per_page = 500): array {
		$response = $this->requestWithToken('GET', '/api/client/lockers', [
			'query' => $this->buildPaginationQuery($page, $per_page)
		], [
			'provider_code' => Settings::COURIER_SAMEDAY_LOCKER,
			'action'        => 'get_lockers'
		]);

		return $this->extractDataList($response, '/api/client/lockers');
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getServices(int $page = 1, int $per_page = 500): array {
		$response = $this->requestWithToken('GET', '/api/client/services', [
			'query' => $this->buildPaginationQuery($page, $per_page)
		], [
			'provider_code' => Settings::COURIER_SAMEDAY_LOCKER,
			'action'        => 'get_services'
		]);

		return $this->extractDataList($response, '/api/client/services');
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $context
	 *
	 * @return array<string, mixed>
	 */
	public function createAwb(array $payload, array $context = []): array {
		$response = $this->requestWithToken('POST', '/api/awb', [
			'form_params' => $payload
		], [
			'provider_code' => Settings::COURIER_SAMEDAY_LOCKER,
			'action'        => 'create_awb',
			'order_id'      => (int)($context['order_id'] ?? 0)
		], false);

		try {
			return $this->decodeJsonResponse($response['body'], '/api/awb');
		} catch (ApiException $exception) {
			$this->logApiError((string)($response['request_id'] ?? ''), (string)($response['action'] ?? 'create_awb'), (int)($response['order_id'] ?? 0), $exception->getMessage(), (string)($response['body'] ?? ''));

			throw $exception;
		}
	}

	/**
	 * @param array<string, mixed> $context
	 *
	 * @return array<string, mixed>
	 */
	public function getAwbStatus(string $awb_number, array $context = []): array {
		$awb_number = trim($awb_number);

		if ($awb_number === '') {
			throw new ApiException('SameDay AWB number is required for status.');
		}

		$response = $this->requestWithToken('GET', '/api/client/awb/' . rawurlencode($awb_number) . '/status', [], [
			'provider_code' => Settings::COURIER_SAMEDAY_LOCKER,
			'action'        => 'awb_status',
			'order_id'      => (int)($context['order_id'] ?? 0)
		]);

		try {
			return $this->decodeJsonResponse($response['body'], '/api/client/awb/' . rawurlencode($awb_number) . '/status');
		} catch (ApiException $exception) {
			$this->logApiError((string)($response['request_id'] ?? ''), (string)($response['action'] ?? 'awb_status'), (int)($response['order_id'] ?? 0), $exception->getMessage(), (string)($response['body'] ?? ''));

			throw $exception;
		}
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function downloadAwbPdf(string $awb_number, string $label_format = 'A6', array $context = []): string {
		$awb_number = trim($awb_number);
		$label_format = strtoupper(trim($label_format)) ?: 'A6';

		if ($awb_number === '') {
			throw new ApiException('SameDay AWB number is required for PDF download.');
		}

		$response = $this->requestWithToken('GET', '/api/awb/download/' . rawurlencode($awb_number) . '/' . rawurlencode($label_format), [], [
			'provider_code' => Settings::COURIER_SAMEDAY_LOCKER,
			'action'        => 'download_awb',
			'order_id'      => (int)($context['order_id'] ?? 0),
			'binary'        => true
		]);

		$body = (string)$response['body'];

		if (strpos(ltrim($body), '{') === 0) {
			try {
				$data = $this->decodeJsonResponse($body, '/api/awb/download/' . rawurlencode($awb_number) . '/' . rawurlencode($label_format));
				throw new ApiException($this->extractApiMessage($data, 'SameDay AWB PDF download returned an API error.'));
			} catch (ApiException $exception) {
				$this->logApiError((string)($response['request_id'] ?? ''), (string)($response['action'] ?? 'download_awb'), (int)($response['order_id'] ?? 0), $exception->getMessage(), $body);

				throw $exception;
			}
		}

		return $body;
	}

	private function hasValidToken(): bool {
		if ($this->token === '') {
			return false;
		}

		if ($this->token_expire_at === '') {
			return true;
		}

		$expiry = strtotime($this->token_expire_at);

		if ($expiry === false) {
			return true;
		}

		return $expiry > (time() + 300);
	}

	private function assertConfigured(): void {
		if (!$this->isConfigured()) {
			throw new ApiException('SameDay username or password is missing.');
		}
	}

	/**
	 * @param array<string, mixed> $options
	 * @param array<string, mixed> $context
	 *
	 * @return array{request_id:string,order_id:int,action:string,status_code:int,body:string}
	 */
	private function requestWithToken(string $method, string $endpoint, array $options = [], array $context = [], bool $fail_on_http_error = true): array {
		$this->authenticate(true);

		$headers = isset($options['headers']) && is_array($options['headers']) ? $options['headers'] : [];
		$headers['X-AUTH-TOKEN'] = $this->token;
		$options['headers'] = $headers;

		return $this->request($method, $endpoint, $options, $fail_on_http_error, $context);
	}

	/**
	 * @param array<string, mixed> $options
	 * @param array<string, mixed> $context
	 *
	 * @return array{request_id:string,order_id:int,action:string,status_code:int,body:string}
	 */
	private function request(string $method, string $endpoint, array $options = [], bool $fail_on_http_error = true, array $context = []): array {
		if (!function_exists('curl_init')) {
			throw new ApiException('cURL is not available on this server.');
		}

		$request_id = $this->generateRequestId();
		$url = $this->base_url . '/' . ltrim($endpoint, '/');

		if (!empty($options['query']) && is_array($options['query'])) {
			$url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($options['query']);
		}

		$curl = curl_init($url);

		if ($curl === false) {
			throw new ApiException('SameDay request could not be initialized.');
		}

		$headers = [];
		$body = null;

		if (!empty($options['headers']) && is_array($options['headers'])) {
			foreach ($options['headers'] as $name => $value) {
				if (!is_string($name) || $name === '') {
					continue;
				}

				$headers[] = $name . ': ' . $value;
			}
		}

		if (isset($options['json'])) {
			$headers[] = 'Content-Type: application/json';
			$encoded = json_encode($options['json']);
			$body = $encoded === false ? '{}' : $encoded;
		} elseif (!empty($options['form_params']) && is_array($options['form_params'])) {
			$headers[] = 'Content-Type: application/x-www-form-urlencoded';
			$body = http_build_query($options['form_params']);
		}

		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($method));
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 20);
		curl_setopt($curl, CURLOPT_TIMEOUT, 30);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);

		if ($headers) {
			curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		}

		if ($body !== null) {
			curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
		}

		$started_at = microtime(true);
		$order_id = (int)($context['order_id'] ?? 0);
		$action = (string)($context['action'] ?? ltrim($endpoint, '/'));

		$this->logApiRequest($request_id, $action, $order_id, strtoupper($method), $url, $this->resolveLoggableRequestPayload($options));
		$raw_body = curl_exec($curl);
		$status_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$duration_ms = (int)round((microtime(true) - $started_at) * 1000);

		if ($raw_body === false) {
			$error = curl_error($curl);
			curl_close($curl);
			$this->logApiError($request_id, $action, $order_id, 'SameDay request failed: ' . $error);

			throw new ApiException('SameDay request failed: ' . $error);
		}

		curl_close($curl);
		$binary_response = !empty($context['binary']) && !$this->isJsonString((string)$raw_body);
		$this->logApiResponse($request_id, $action, $order_id, $status_code, (string)$raw_body, $duration_ms, $binary_response);

		if ($fail_on_http_error && $status_code >= 400) {
			throw new ApiException('SameDay endpoint ' . $endpoint . ' returned HTTP ' . $status_code . '.');
		}

		return [
			'order_id'    => $order_id,
			'action'      => $action,
			'request_id'  => $request_id,
			'status_code' => $status_code,
			'body'        => (string)$raw_body
		];
	}

	/**
	 * @param array{request_id:string,order_id:int,action:string,status_code:int,body:string} $response
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function extractDataList(array $response, string $endpoint): array {
		try {
			$data = $this->decodeJsonResponse($response['body'], $endpoint);

			if (!isset($data['data']) || !is_array($data['data'])) {
				return [];
			}

			$items = [];

			foreach ($data['data'] as $item) {
				if (is_array($item)) {
					$items[] = $item;
				}
			}

			return $items;
		} catch (ApiException $exception) {
			$this->logApiError((string)($response['request_id'] ?? ''), (string)($response['action'] ?? $endpoint), (int)($response['order_id'] ?? 0), $exception->getMessage(), (string)($response['body'] ?? ''));

			throw $exception;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decodeJsonResponse(string $body, string $endpoint): array {
		$data = json_decode($body, true);

		if (!is_array($data)) {
			throw new ApiException('SameDay endpoint ' . $endpoint . ' did not return valid JSON.');
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function extractApiMessage(array $data, string $fallback): string {
		$message = trim((string)($data['message'] ?? ''));

		if ($message !== '') {
			return $message;
		}

		if (!empty($data['error']) && is_string($data['error'])) {
			return trim($data['error']);
		}

		return $fallback;
	}

	/**
	 * @return array<string, int>
	 */
	private function buildPaginationQuery(int $page, int $per_page): array {
		return [
			'page'         => max($page, 1),
			'countPerPage' => max($per_page, 1),
			'perPage'      => max($per_page, 1)
		];
	}

	/**
	 * @param array<string, mixed> $options
	 *
	 * @return array<string, mixed>
	 */
	private function resolveLoggableRequestPayload(array $options): array {
		$payload = [];

		if (isset($options['query']) && is_array($options['query'])) {
			$payload['query'] = $options['query'];
		}

		if (isset($options['headers']) && is_array($options['headers'])) {
			$payload['headers'] = $options['headers'];
		}

		if (isset($options['form_params']) && is_array($options['form_params'])) {
			$payload['form_params'] = $options['form_params'];
		}

		if (isset($options['json']) && is_array($options['json'])) {
			$payload['json'] = $options['json'];
		}

		return $payload;
	}

	private function generateRequestId(): string {
		try {
			return bin2hex(random_bytes(8));
		} catch (\Throwable $exception) {
			return uniqid('smd_', true);
		}
	}

	private function isJsonString(string $value): bool {
		$value = ltrim($value);

		return $value !== '' && ($value[0] === '{' || $value[0] === '[');
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function logApiRequest(string $request_id, string $action, int $order_id, string $method, string $url, array $payload): void {
		$this->api_logger->write('[SamedayApi][request] ' . $this->encodeLogLine([
			'request_id' => $request_id,
			'action'     => $action,
			'order_id'   => $order_id,
			'method'     => $method,
			'url'        => $url,
			'payload'    => $this->sanitizePayload($payload)
		]));
	}

	private function logApiResponse(string $request_id, string $action, int $order_id, int $status_code, string $body, int $duration_ms, bool $binary_response = false): void {
		$this->api_logger->write('[SamedayApi][response] ' . $this->encodeLogLine([
			'request_id'  => $request_id,
			'action'      => $action,
			'order_id'    => $order_id,
			'status_code' => $status_code,
			'duration_ms' => $duration_ms,
			'payload'     => $binary_response ? '[binary response omitted; ' . strlen($body) . ' bytes]' : $this->sanitizePayload($body)
		]));
	}

	private function logApiError(string $request_id, string $action, int $order_id, string $message, string $body = ''): void {
		$data = [
			'request_id' => $request_id,
			'action'     => $action,
			'order_id'   => $order_id,
			'message'    => $message
		];

		if ($body !== '') {
			$data['payload'] = $this->sanitizePayload($body);
		}

		$this->api_logger->write('[SamedayApi][error] ' . $this->encodeLogLine($data));
	}

	/**
	 * @param mixed $payload
	 */
	private function sanitizePayload($payload): string {
		if (is_array($payload)) {
			$payload = $this->sanitizeArray($payload);
		} elseif (is_string($payload)) {
			$trimmed = trim($payload);

			if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
				$decoded = json_decode($trimmed, true);

				if (is_array($decoded)) {
					$payload = $this->sanitizeArray($decoded);
				}
			}
		}

		return $this->truncateLogValue($this->encodeLogLine($payload));
	}

	/**
	 * @param mixed $payload
	 */
	private function encodeLogLine($payload): string {
		$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return $json === false ? '{}' : $json;
	}

	/**
	 * @param array<int|string, mixed> $payload
	 *
	 * @return array<int|string, mixed>
	 */
	private function sanitizeArray(array $payload): array {
		$sanitized = [];

		foreach ($payload as $key => $value) {
			$key_name = is_string($key) ? strtolower($key) : '';

			if (is_array($value)) {
				$sanitized[$key] = $this->sanitizeArray($value);
				continue;
			}

			$sanitized[$key] = $this->sanitizeScalarValue($key_name, $value);
		}

		return $sanitized;
	}

	/**
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	private function sanitizeScalarValue(string $key, $value) {
		if (!is_scalar($value) && $value !== null) {
			return '[complex value omitted]';
		}

		$value = (string)$value;

		if (in_array($key, ['user', 'username', 'pwd', 'password', 'token', 'x-auth-token', 'x-auth-username', 'x-auth-password', 'authorization'], true)) {
			return '[redacted]';
		}

		if (in_array($key, ['phone', 'telephone', 'mobile'], true)) {
			return $this->maskPhone($value);
		}

		if ($key === 'email') {
			return $this->maskEmail($value);
		}

		return $value;
	}

	private function maskPhone(string $value): string {
		$digits = preg_replace('/\D+/', '', $value) ?? '';

		if (strlen($digits) <= 4) {
			return '[redacted]';
		}

		return substr($digits, 0, 2) . str_repeat('*', max(strlen($digits) - 4, 0)) . substr($digits, -2);
	}

	private function maskEmail(string $value): string {
		if (strpos($value, '@') === false) {
			return '[redacted]';
		}

		[$local, $domain] = explode('@', $value, 2);

		if ($local === '' || $domain === '') {
			return '[redacted]';
		}

		return substr($local, 0, 1) . str_repeat('*', max(strlen($local) - 1, 0)) . '@' . $domain;
	}

	private function truncateLogValue(string $value, int $limit = 16384): string {
		if (strlen($value) <= $limit) {
			return $value;
		}

		return substr($value, 0, $limit) . '[truncated]';
	}
}
