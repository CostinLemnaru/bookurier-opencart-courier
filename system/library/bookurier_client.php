<?php
namespace Opencart\System\Library\Extension\Bookurier;

class BookurierClient {
	private string $username;
	private string $password;
	private string $api_key;
	private string $base_url;
	private object $api_logger;

	public function __construct(string $username = '', string $password = '', string $api_key = '', string $base_url = Settings::BOOKURIER_API_BASE_URL) {
		$this->username = trim($username);
		$this->password = trim($password);
		$this->api_key = trim($api_key);
		$this->base_url = rtrim($base_url, '/') . '/';
		$this->api_logger = Platform::createLog(Settings::API_LOG_FILE);
	}

	public function isConfigured(): bool {
		return $this->username !== '' && $this->password !== '';
	}

	public function hasTrackingConfiguration(): bool {
		return $this->api_key !== '';
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $context
	 *
	 * @return array<string, mixed>
	 */
	public function createAwb(array $payload, array $context = []): array {
		$this->assertConfigured();

		$response = $this->request('POST', 'add_cmds.php', [
			'json' => [
				'user' => $this->username,
				'pwd'  => $this->password,
				'data' => [$payload]
			]
		], true, [
			'provider_code' => Settings::COURIER_BOOKURIER,
			'action'        => 'create_awb',
			'order_id'      => (int)($context['order_id'] ?? 0)
		]);

		try {
			$data = $this->decodeJsonResponse($response['body'], 'add_cmds.php');

			if (strtolower((string)($data['status'] ?? '')) !== 'success') {
				throw new ApiException($this->extractApiMessage($data, 'Bookurier create AWB failed.'));
			}

			return $data;
		} catch (ApiException $exception) {
			$this->logApiError((string)($response['request_id'] ?? ''), (string)($response['action'] ?? 'create_awb'), (int)($response['order_id'] ?? 0), $exception->getMessage(), (string)($response['body'] ?? ''));

			throw $exception;
		}
	}

	/**
	 * @param array<int, string> $awb_codes
	 * @param array<string, mixed> $context
	 */
	public function printAwbs(array $awb_codes, string $format = 'pdf', string $mode = 'm', int $page = 0, array $context = []): string {
		$this->assertConfigured();

		$response = $this->request('POST', 'print_awbs.php', [
			'json' => [
				'user'   => $this->username,
				'pwd'    => $this->password,
				'format' => $format,
				'mode'   => $mode,
				'page'   => $page,
				'data'   => array_values($awb_codes)
			]
		], false, [
			'provider_code' => Settings::COURIER_BOOKURIER,
			'action'        => 'print_awb',
			'order_id'      => (int)($context['order_id'] ?? 0),
			'binary'        => true
		]);

		$body = (string)$response['body'];

		if (strpos(ltrim($body), '{') === 0) {
			try {
				$data = $this->decodeJsonResponse($body, 'print_awbs.php');

				if (strtolower((string)($data['status'] ?? '')) === 'error') {
					throw new ApiException($this->extractApiMessage($data, 'Bookurier print AWB failed.'));
				}
			} catch (ApiException $exception) {
				$this->logApiError((string)($response['request_id'] ?? ''), (string)($response['action'] ?? 'print_awb'), (int)($response['order_id'] ?? 0), $exception->getMessage(), (string)($response['body'] ?? ''));

				throw $exception;
			}
		}

		return $body;
	}

	/**
	 * @param array<string, mixed> $context
	 *
	 * @return array<string, mixed>
	 */
	public function getAwbHistory(string $awb_code, array $context = []): array {
		$awb_code = trim($awb_code);

		if (!$this->hasTrackingConfiguration()) {
			throw new ApiException('Bookurier API key is missing.');
		}

		if ($awb_code === '') {
			throw new ApiException('Bookurier AWB code is missing.');
		}

		$response = $this->request('GET', 'awb_history.php', [
			'query' => [
				'key' => $this->api_key,
				'awb' => $awb_code
			]
		], true, [
			'provider_code' => Settings::COURIER_BOOKURIER,
			'action'        => 'awb_history',
			'order_id'      => (int)($context['order_id'] ?? 0)
		]);

		try {
			$data = $this->decodeJsonResponse($response['body'], 'awb_history.php');

			if (strtolower((string)($data['status'] ?? '')) !== 'success') {
				throw new ApiException($this->extractApiMessage($data, 'Bookurier AWB history request failed.'));
			}

			return $data;
		} catch (ApiException $exception) {
			$this->logApiError((string)($response['request_id'] ?? ''), (string)($response['action'] ?? 'awb_history'), (int)($response['order_id'] ?? 0), $exception->getMessage(), (string)($response['body'] ?? ''));

			throw $exception;
		}
	}

	private function assertConfigured(): void {
		if (!$this->isConfigured()) {
			throw new ApiException('Bookurier username or password is missing.');
		}
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
		$url = $this->base_url . ltrim($endpoint, '/');

		if (!empty($options['query']) && is_array($options['query'])) {
			$url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($options['query']);
		}

		$curl = curl_init($url);

		if ($curl === false) {
			throw new ApiException('Bookurier request could not be initialized.');
		}

		$headers = [];
		$body = null;

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
		$action = (string)($context['action'] ?? $endpoint);
		$this->logApiRequest($request_id, $action, $order_id, strtoupper($method), $url, $this->resolveLoggableRequestPayload($options));
		$raw_body = curl_exec($curl);
		$status_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$duration_ms = (int)round((microtime(true) - $started_at) * 1000);

		if ($raw_body === false) {
			$error = curl_error($curl);
			curl_close($curl);
			$this->logApiError($request_id, $action, $order_id, 'Bookurier request failed: ' . $error);

			throw new ApiException('Bookurier request failed: ' . $error);
		}

		curl_close($curl);
		$binary_response = !empty($context['binary']) && !$this->isJsonString((string)$raw_body);
		$this->logApiResponse($request_id, $action, $order_id, $status_code, (string)$raw_body, $duration_ms, $binary_response);

		if ($fail_on_http_error && $status_code >= 400) {
			throw new ApiException('Bookurier endpoint ' . $endpoint . ' returned HTTP ' . $status_code . '.');
		}

		return [
			'order_id'    => (int)($context['order_id'] ?? 0),
			'action'      => (string)($context['action'] ?? $endpoint),
			'request_id'  => $request_id,
			'status_code' => $status_code,
			'body'        => (string)$raw_body
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decodeJsonResponse(string $body, string $endpoint): array {
		$data = json_decode($body, true);

		if (!is_array($data)) {
			throw new ApiException('Bookurier endpoint ' . $endpoint . ' did not return valid JSON.');
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function extractApiMessage(array $data, string $fallback): string {
		$message = trim((string)($data['message'] ?? ''));

		return $message !== '' ? $message : $fallback;
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

		if (isset($options['form_params']) && is_array($options['form_params'])) {
			$payload['form_params'] = $options['form_params'];
		}

		if (isset($options['json']) && is_array($options['json'])) {
			$payload['json'] = $options['json'];
		}

		if (isset($options['body']) && !is_array($options['body'])) {
			$payload['body'] = (string)$options['body'];
		}

		return $payload;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function generateRequestId(): string {
		try {
			return bin2hex(random_bytes(8));
		} catch (\Throwable $exception) {
			return uniqid('bkr_', true);
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
		$this->api_logger->write('[BookurierApi][request] ' . $this->encodeLogLine([
			'request_id' => $request_id,
			'action'     => $action,
			'order_id'   => $order_id,
			'method'     => $method,
			'url'        => $url,
			'payload'    => $this->sanitizePayload($payload)
		]));
	}

	private function logApiResponse(string $request_id, string $action, int $order_id, int $status_code, string $body, int $duration_ms, bool $binary_response): void {
		$this->api_logger->write('[BookurierApi][response] ' . $this->encodeLogLine([
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

		$this->api_logger->write('[BookurierApi][error] ' . $this->encodeLogLine($data));
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

		if (in_array($key, ['user', 'username', 'pwd', 'password', 'key', 'api_key', 'token', 'authorization'], true)) {
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
