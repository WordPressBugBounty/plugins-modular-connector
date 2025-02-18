<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Auth;

use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Config;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
class JWT
{
    private static $SUPPORTED_ALGORITHMS = ['HS256' => ['hash' => 'sha256', 'type' => 'hmac'], 'HS384' => ['hash' => 'sha384', 'type' => 'hmac'], 'HS512' => ['hash' => 'sha512', 'type' => 'hmac'], 'RS256' => ['hash' => 'sha256', 'type' => 'rsa'], 'RS384' => ['hash' => 'sha384', 'type' => 'rsa'], 'RS512' => ['hash' => 'sha512', 'type' => 'rsa']];
    /**
     * Creates a new JWT token.
     *
     * @param string $action Action used for the cron token
     *
     * @return string           The generated token
     * @throws \Exception
     */
    public static function generate($action)
    {
        $key = Config::get('hashing.default_key', \false);
        if (!$key) {
            throw new \Exception('No key specified for JWT generation.');
        }
        $algorithm = Config::get('hashing.algorithm', 'HS512');
        $expiration = Config::get('hashing.default_expiration', 24 * 60);
        if (!isset(self::$SUPPORTED_ALGORITHMS[$algorithm])) {
            throw new \Exception("Unsupported algorithm: {$algorithm}");
        }
        $header = json_encode(['typ' => 'JWT', 'alg' => $algorithm]);
        $claims = [
            'exp' => Carbon::now()->addMinutes($expiration)->timestamp,
            'iat' => Carbon::now()->timestamp,
            // issued at
            'action' => $action,
        ];
        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode(json_encode($claims));
        $signature = self::sign("{$base64UrlHeader}.{$base64UrlPayload}", $key, $algorithm);
        $base64UrlSignature = self::base64UrlEncode($signature);
        return "{$base64UrlHeader}.{$base64UrlPayload}.{$base64UrlSignature}";
    }
    /**
     * Encodes the given input to Base64Url format.
     *
     * @param string $input The input to encode.
     *
     * @return string       The Base64Url encoded string.
     */
    private static function base64UrlEncode($input)
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($input));
    }
    /**
     * Signs the given input using the specified algorithm and key.
     *
     * @param string $input The input to sign.
     * @param string|resource $key The key to use for signing.
     * @param string $algorithm The algorithm to use.
     *
     * @return string                       The signature.
     */
    private static function sign($input, $key, $algorithm)
    {
        $algDetails = self::$SUPPORTED_ALGORITHMS[$algorithm];
        if ($algDetails['type'] === 'hmac') {
            return hash_hmac($algDetails['hash'], $input, $key, \true);
        }
        if ($algDetails['type'] === 'rsa') {
            $signature = '';
            openssl_sign($input, $signature, $key, $algDetails['hash']);
            return $signature;
        }
        throw new \Exception('Unsupported algorithm type.');
    }
    /**
     * Verify the validity of a JWT token.
     *
     * @param string $token The JWT token to verify.
     * @param string $action The action to check
     *
     * @return bool             `true` if the token is valid. `false` otherwise
     * @throws \Exception
     */
    public static function verify($token, $action)
    {
        if (!$token || !Str::contains($token, 'Bearer')) {
            wp_die(sprintf('Missing token for %s', $action), 403);
        }
        $token = Str::replace('Bearer ', '', $token);
        $jwtParts = self::explodeToken($token);
        $header = json_decode(base64_decode($jwtParts[0]));
        $payload = json_decode(base64_decode($jwtParts[1]));
        $signature = self::base64UrlDecode($jwtParts[2]);
        $algorithm = Config::get('hashing.algorithm', \false);
        $key = Config::get('hashing.default_key', \false);
        $alg = \Modular\ConnectorDependencies\data_get($header, 'alg', $algorithm);
        if ($algorithm !== $alg) {
            return \false;
        }
        // Check signature before decrypting
        $base64UrlHeader = $jwtParts[0];
        $base64UrlPayload = $jwtParts[1];
        if (!self::verifySignature("{$base64UrlHeader}.{$base64UrlPayload}", $signature, $key, $alg)) {
            return \false;
        }
        $currentTime = Carbon::now()->timestamp;
        // Check claims
        if ($currentTime > \Modular\ConnectorDependencies\data_get($payload, 'exp', 0)) {
            return \false;
        }
        if ($currentTime < \Modular\ConnectorDependencies\data_get($payload, 'iat', 0)) {
            return \false;
        }
        return $action === \Modular\ConnectorDependencies\data_get($payload, 'action', '');
    }
    /**
     * Separates into an array the different parts of a given JWT token
     *
     * @param string $token The token to explode.
     *
     * @return array                The separated parts of the given token.
     * @throws \Exception
     */
    private static function explodeToken($token)
    {
        $jwtParts = explode('.', $token);
        if (count($jwtParts) < 3) {
            throw new \Exception('Invalid token structure.');
        }
        return $jwtParts;
    }
    /**
     * Decodes the given Base64Url encoded string.
     *
     * @param string $input The Base64Url encoded string to decode.
     *
     * @return string       The decoded string
     */
    private static function base64UrlDecode($input)
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $padLen = 4 - $remainder;
            $input .= str_repeat('=', $padLen);
        }
        return base64_decode(strtr($input, '-_', '+/'));
    }
    /**
     * Verifies the signature of the given input.
     *
     * @param string $input The input that was signed.
     * @param string $signature The signature to verify.
     * @param string|resource $key The key to use for verification.
     * @param string $algorithm The algorithm used for signing.
     *
     * @return bool                         `true` if the signature is valid, `false` otherwise.
     */
    private static function verifySignature($input, $signature, $key, $algorithm)
    {
        $algDetails = self::$SUPPORTED_ALGORITHMS[$algorithm];
        if ($algDetails['type'] === 'hmac') {
            $expectedSignature = self::sign($input, $key, $algorithm);
            return hash_equals($expectedSignature, $signature);
        }
        if ($algDetails['type'] === 'rsa') {
            return openssl_verify($input, $signature, $key, $algDetails['hash']) === 1;
        }
        throw new \Exception('Unsupported algorithm type');
    }
}
