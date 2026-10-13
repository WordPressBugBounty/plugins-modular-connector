<?php

namespace Modular\Connector\Health\Tests;

use Modular\Connector\Health\AbstractHealthTest;
use Modular\Connector\Helper\Loopback;
use Modular\ConnectorDependencies\Illuminate\Http\Request;

/**
 * Core's `get_test_authorization_header()` never sends a request: it inspects the
 * `PHP_AUTH_*` vars of the request it runs in and expects `user:pwd`, which only wp-admin's
 * browser call supplies (core marks the test `skip_cron` for that reason). Run from the
 * queue it always reports the header missing, so this test sends the header itself through
 * the signed loopback and lets `ServerController::getLoopback()` report what PHP saw.
 */
class AuthorizationHeaderTest extends AbstractHealthTest
{
    /**
     * JWT claim marking the loopback as this probe, and the key the probe answers under.
     */
    public const PROBE = 'authorization_header';

    /**
     * Credentials core's Site Health sends, so a proxy tuned for them behaves the same.
     */
    private const USER = 'user';
    private const PASSWORD = 'pwd';

    /**
     * @return string
     */
    public function identifier()
    {
        return self::PROBE;
    }

    /**
     * Whether the loopback request currently being handled is this probe.
     *
     * @param Request $request
     * @return bool
     */
    public static function isProbe(Request $request): bool
    {
        $payload = $request->attributes->get('jwt_payload');

        return is_object($payload) && ($payload->probe ?? null) === self::PROBE;
    }

    /**
     * Answer the probe from inside the loopback: did the header survive to PHP?
     *
     * Same check as core. Servers that only expose `HTTP_AUTHORIZATION` are covered by
     * WordPress's own copy into `PHP_AUTH_*`, repeated here in case it has not run.
     *
     * @return array{authorization_header: bool}
     */
    public static function observe(): array
    {
        if (function_exists('wp_populate_basic_auth_from_authorization_header')) {
            \wp_populate_basic_auth_from_authorization_header();
        }

        $received = isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])
            && $_SERVER['PHP_AUTH_USER'] === self::USER
            && $_SERVER['PHP_AUTH_PW'] === self::PASSWORD;

        return [self::PROBE => $received];
    }

    /**
     * @return array
     */
    public function run()
    {
        // Sites behind .htpasswd need their own credentials on every request, so the probe's
        // pair would be rejected before reaching PHP: the header evidently gets through.
        if (function_exists('wp_is_site_protected_by_basic_auth') && \wp_is_site_protected_by_basic_auth()) {
            return $this->result('good');
        }

        $nonce = Loopback::nonce();
        $args = Loopback::args($nonce, ['probe' => self::PROBE], true);

        if (is_null($args)) {
            return $this->result('critical', [
                'response_code' => 0,
                'error' => 'The site holds no client credentials to sign the probe with.',
            ]);
        }

        $args['headers']['Authorization'] = 'Basic ' . base64_encode(self::USER . ':' . self::PASSWORD);

        $timed = $this->timedCoreCall(fn() => \wp_remote_get(Loopback::url($nonce), $args));
        $response = $timed['result'];

        if (\is_wp_error($response)) {
            return $this->result('critical', [
                'response_code' => 0,
                'response_time_ms' => $timed['response_time_ms'],
                'error' => $response->get_error_message(),
            ]);
        }

        $code = (int)\wp_remote_retrieve_response_code($response);
        $body = json_decode((string)\wp_remote_retrieve_body($response), true);

        if ($code !== 200 || !is_array($body) || !array_key_exists(self::PROBE, $body)) {
            return $this->result('recommended', [
                'response_code' => $code,
                'response_time_ms' => $timed['response_time_ms'],
                'error' => sprintf('Unexpected response from the loopback probe (HTTP %d).', $code),
            ]);
        }

        if (!$body[self::PROBE]) {
            return $this->result('recommended', [
                'response_code' => $code,
                'response_time_ms' => $timed['response_time_ms'],
                'error' => 'The Authorization header is missing: the server strips it before PHP handles the request.',
            ]);
        }

        return $this->result('good');
    }
}
