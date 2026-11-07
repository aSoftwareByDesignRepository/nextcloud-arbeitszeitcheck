<?php

declare(strict_types=1);

/**
 * APNs Live-Activity push plumbing (SPEC NH-P3, D-03 scope: azc-clock only).
 *
 * Stores per-device Activity push tokens as user values (no schema change —
 * 'azc_la_token_<deviceHash>' keys, wiped with the account) and sends
 * push-type=liveactivity updates over the APNs HTTP/2 provider API using an
 * ES256 provider-token JWT built from the configured .p8 key.
 *
 * Deployment config (appconfig, never committed):
 *   apns_live_p8      — .p8 private key PEM contents (ES256)
 *   apns_live_kid     — APNs key id
 *   apns_live_tid     — Apple team id
 *   apns_live_bundle  — widget extension bundle id (…checkwidget)
 *   apns_live_sandbox — '1' routes to api.sandbox.push.apple.com
 *
 * Absence of config = feature dark: register calls still store tokens, the
 * send path is a no-op that never throws — clock execution is unaffected.
 */
namespace OCA\ArbeitszeitCheck\Service;

use OCP\IConfig;
use OCP\IUserSession;
use OCP\AppFramework\Services\IAppConfig;

class LiveActivityPushService
{
	private const TOKEN_PREFIX = 'azc_la_token_';

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
	) {
	}

	/** Store/replace a device's Activity push token for the user. */
	public function registerToken(string $uid, string $activityToken, ?string $deviceLabel = null): void
	{
		if (!preg_match('/^[0-9a-f]{64,128}$/i', $activityToken)) {
			throw new \InvalidArgumentException('invalid activity push token');
		}
		$key = self::TOKEN_PREFIX . hash('sha256', $deviceLabel ?? $activityToken);
		$this->config->setUserValue($uid, 'arbeitszeitcheck', $key, $activityToken);
	}

	public function unregisterToken(string $uid, ?string $deviceLabel = null): void
	{
		foreach ($this->tokensFor($uid) as $key => $_) {
			if ($deviceLabel === null || $key === self::TOKEN_PREFIX . hash('sha256', $deviceLabel)) {
				$this->config->deleteUserValue($uid, 'arbeitszeitcheck', $key);
			}
		}
	}

	/** @return array<string,string> storage-key => token */
	public function tokensFor(string $uid): array
	{
		$out = [];
		foreach ($this->config->getUserKeys($uid, 'arbeitszeitcheck') as $key) {
			if (str_starts_with($key, self::TOKEN_PREFIX)) {
				$out[$key] = $this->config->getUserValue($uid, 'arbeitszeitcheck', $key);
			}
		}
		return $out;
	}

	/**
	 * Push a coarse-state Live Activity update (MH-P21) to every registered
	 * device token. Never throws — push is best-effort beside the stamp.
	 */
	public function sendClockUpdate(string $uid, bool $clockedIn, string $headline, string $secondary = ''): void
	{
		$jwt = $this->providerJwt();
		if ($jwt === null) return; // unconfigured deployment → dark feature
		$bundle = $this->appConfig->getAppValueString('apns_live_bundle');
		if ($bundle === '') return;
		$host = $this->appConfig->getAppValueString('apns_live_sandbox') === '1'
			? 'https://api.sandbox.push.apple.com' : 'https://api.push.apple.com';
		$payload = json_encode(['aps' => [
			'timestamp' => time(),
			'event' => 'update',
			'content-state' => ['headline' => $headline, 'badge' => $clockedIn ? 'IN' : 'OUT', 'secondary' => $secondary],
			'attributes' => new \stdClass(),
			'attributes-type' => 'CheckActivityAttributes',
		]], JSON_THROW_ON_ERROR);
		foreach ($this->tokensFor($uid) as $token) {
			$this->post($host . '/3/device/' . $token, $jwt, $bundle . '.push-type.liveactivity', $payload);
		}
	}

	/** End all Live Activities on clock-out finality (event=end). */
	public function sendClockEnd(string $uid): void
	{
		$jwt = $this->providerJwt();
		if ($jwt === null) return;
		$bundle = $this->appConfig->getAppValueString('apns_live_bundle');
		if ($bundle === '') return;
		$host = $this->appConfig->getAppValueString('apns_live_sandbox') === '1'
			? 'https://api.sandbox.push.apple.com' : 'https://api.push.apple.com';
		$payload = json_encode(['aps' => [
			'timestamp' => time(),
			'event' => 'end',
			'content-state' => new \stdClass(),
			'dismissal-date' => time(),
		]], JSON_THROW_ON_ERROR);
		foreach ($this->tokensFor($uid) as $token) {
			$this->post($host . '/3/device/' . $token, $jwt, $bundle . '.push-type.liveactivity', $payload);
		}
	}

	/** ES256 provider JWT — 60 min lifetime, re-issued per send batch. */
	private function providerJwt(): ?string
	{
		$p8 = $this->appConfig->getAppValueString('apns_live_p8');
		$kid = $this->appConfig->getAppValueString('apns_live_kid');
		$tid = $this->appConfig->getAppValueString('apns_live_tid');
		if ($p8 === '' || $kid === '' || $tid === '') return null;
		$b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
		$head = $b64(json_encode(['alg' => 'ES256', 'kid' => $kid]));
		$claims = $b64(json_encode(['iss' => $tid, 'iat' => time()]));
		$key = openssl_pkey_get_private($p8);
		if ($key === false) return null;
		if (!openssl_sign("$head.$claims", $sig, $key, OPENSSL_ALGO_SHA256)) return null;
		return "$head.$claims." . $b64($sig);
	}

	private function post(string $url, string $jwt, string $topic, string $payload): void
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $payload,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
			CURLOPT_TIMEOUT => 5,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => [
				'authorization: bearer ' . $jwt,
				'apns-push-type: liveactivity',
				'apns-topic: ' . $topic,
				'apns-priority: 10',
			],
		]);
		curl_exec($ch); // best-effort — errors must never fail the stamp
		curl_close($ch);
	}
}
