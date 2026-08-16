<?php
declare(strict_types=1);

/**
 * Amazon Cognito Hosted UI — OpenID Connect Authorization Code flow with PKCE.
 *
 * The round trip, in order:
 *
 *   1. login.php    -> Cognito::authorizeUrl()  : browser leaves for the Hosted UI
 *   2. user signs up / signs in / verifies email on Cognito's pages
 *   3. Cognito redirects back to callback.php with ?code=...&state=...
 *   4. callback.php -> Cognito::exchangeCode()  : server-to-server swap for tokens
 *   5. callback.php -> Cognito::verifyIdToken() : signature + claim checks
 *   6. Auth::loginFromClaims()                  : find/create the local user row
 *
 * Step 4 is an outbound HTTPS call made from a private subnet, which is exactly
 * why the architecture has a NAT gateway.
 */
final class Cognito
{
    private const SCOPES = 'openid email profile';
    private const JWKS_CACHE_TTL = 86400; // 24 hours
    private const CLOCK_SKEW = 60;        // seconds of tolerance on exp/iat

    /** e.g. https://cognito-idp.us-east-1.amazonaws.com/us-east-1_AbC123 */
    public static function issuer(): string
    {
        return sprintf(
            'https://cognito-idp.%s.amazonaws.com/%s',
            Config::mustGet('cognito.region'),
            Config::mustGet('cognito.user_pool_id')
        );
    }

    /** e.g. https://letsvote-auth.auth.us-east-1.amazoncognito.com */
    private static function hostedUiBase(): string
    {
        return 'https://' . rtrim(Config::mustGet('cognito.domain'), '/');
    }

    public static function redirectUri(): string
    {
        return Http::url('/callback.php');
    }

    /**
     * Builds the Hosted UI URL and stashes the one-time values we must be able
     * to compare against when the browser comes back.
     *
     * @param bool $signUp jump straight to the "create account" tab
     */
    public static function authorizeUrl(bool $signUp = false): string
    {
        $state         = bin2hex(random_bytes(16));
        $nonce         = bin2hex(random_bytes(16));
        $codeVerifier  = Jwt::base64UrlEncode(random_bytes(48));
        $codeChallenge = Jwt::base64UrlEncode(hash('sha256', $codeVerifier, true));

        Session::set('oauth_state', $state);
        Session::set('oauth_nonce', $nonce);
        Session::set('oauth_code_verifier', $codeVerifier);

        $endpoint = self::hostedUiBase() . ($signUp ? '/signup' : '/oauth2/authorize');

        return $endpoint . '?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => Config::mustGet('cognito.client_id'),
            'redirect_uri'          => self::redirectUri(),
            'scope'                 => self::SCOPES,
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    public static function logoutUrl(): string
    {
        return self::hostedUiBase() . '/logout?' . http_build_query([
            'client_id'  => Config::mustGet('cognito.client_id'),
            'logout_uri' => Http::url('/'),
        ]);
    }

    /**
     * Swaps the one-time authorization code for tokens.
     *
     * @return array{id_token:string,access_token:string,expires_in:int}
     */
    public static function exchangeCode(string $code, string $codeVerifier): array
    {
        $response = self::httpPost(self::hostedUiBase() . '/oauth2/token', [
            'grant_type'    => 'authorization_code',
            'client_id'     => Config::mustGet('cognito.client_id'),
            'code'          => $code,
            'redirect_uri'  => self::redirectUri(),
            'code_verifier' => $codeVerifier,
        ]);

        if (!isset($response['id_token'])) {
            throw new RuntimeException(
                'Cognito token endpoint returned no id_token: ' . json_encode($response)
            );
        }

        return $response;
    }

    /**
     * Verifies the ID token signature and every claim that matters, then hands
     * back the claims. Anything suspicious throws.
     *
     * @return array<string,mixed>
     */
    public static function verifyIdToken(string $idToken, string $expectedNonce): array
    {
        $claims = Jwt::decodeRs256($idToken, self::jwks());
        $now    = time();

        if (($claims['iss'] ?? '') !== self::issuer()) {
            throw new RuntimeException('ID token issuer mismatch.');
        }

        if (($claims['token_use'] ?? '') !== 'id') {
            throw new RuntimeException('Expected an ID token, got token_use=' . ($claims['token_use'] ?? '?'));
        }

        $audience = $claims['aud'] ?? '';
        $audience = is_array($audience) ? $audience : [$audience];
        if (!in_array(Config::mustGet('cognito.client_id'), $audience, true)) {
            throw new RuntimeException('ID token audience mismatch.');
        }

        if (!isset($claims['exp']) || (int) $claims['exp'] + self::CLOCK_SKEW < $now) {
            throw new RuntimeException('ID token has expired.');
        }

        if (isset($claims['iat']) && (int) $claims['iat'] - self::CLOCK_SKEW > $now) {
            throw new RuntimeException('ID token was issued in the future; check instance clock skew.');
        }

        // Ties this token to the login request that started in *this* browser
        // session, which is what stops a replayed token from logging someone in.
        if (!hash_equals($expectedNonce, (string) ($claims['nonce'] ?? ''))) {
            throw new RuntimeException('ID token nonce mismatch.');
        }

        if (empty($claims['sub'])) {
            throw new RuntimeException('ID token has no subject (sub) claim.');
        }

        return $claims;
    }

    /**
     * Fetches the pool's public keys, cached on disk so we are not calling
     * Cognito on every single login.
     *
     * @return array<string,mixed>
     */
    private static function jwks(): array
    {
        $cacheFile = sprintf(
            '%s/letsvote-jwks-%s.json',
            sys_get_temp_dir(),
            preg_replace('/[^A-Za-z0-9_\-]/', '_', Config::mustGet('cognito.user_pool_id'))
        );

        if (is_readable($cacheFile) && (time() - (int) filemtime($cacheFile)) < self::JWKS_CACHE_TTL) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['keys'])) {
                return $cached;
            }
        }

        $jwks = self::httpGet(self::issuer() . '/.well-known/jwks.json');
        if (empty($jwks['keys'])) {
            throw new RuntimeException('JWKS document from Cognito contained no keys.');
        }

        // Write via a temp file so a concurrent request never reads half a file.
        $tmp = $cacheFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($jwks)) !== false) {
            @rename($tmp, $cacheFile);
        }

        return $jwks;
    }

    /** @return array<string,mixed> */
    private static function httpGet(string $url): array
    {
        return self::request($url, null);
    }

    /**
     * @param  array<string,string> $fields
     * @return array<string,mixed>
     */
    private static function httpPost(string $url, array $fields): array
    {
        return self::request($url, $fields);
    }

    /**
     * @param  array<string,string>|null $postFields
     * @return array<string,mixed>
     */
    private static function request(string $url, ?array $postFields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        if ($postFields !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
            // Cognito app clients with a secret authenticate with HTTP Basic.
            $secret = Config::get('cognito.client_secret', '');
            if ($secret !== '') {
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_USERPWD, Config::mustGet('cognito.client_id') . ':' . $secret);
            }
        }

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Request to Cognito failed: {$error}");
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Cognito returned HTTP {$status}: {$body}");
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Cognito returned a non-JSON response.');
        }

        return $decoded;
    }
}
