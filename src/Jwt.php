<?php
declare(strict_types=1);

/**
 * Minimal RS256 JWT verifier — no Composer, no third-party library.
 *
 * Cognito signs its ID tokens with RSA-SHA256 and publishes the matching
 * public keys as a JWKS document. A JWKS key gives us the RSA modulus (n)
 * and exponent (e) as base64url numbers, but PHP's openssl_verify() wants a
 * PEM public key, so we assemble the DER/ASN.1 structure ourselves:
 *
 *   SubjectPublicKeyInfo ::= SEQUENCE {
 *       algorithm  SEQUENCE { OID rsaEncryption, NULL },
 *       publicKey  BIT STRING wrapping SEQUENCE { INTEGER n, INTEGER e }
 *   }
 *
 * This is the one genuinely fiddly file in the project; everything else is
 * ordinary PHP. Read it once, then trust it.
 */
final class Jwt
{
    /** DER bytes for the rsaEncryption OID 1.2.840.113549.1.1.1 */
    private const OID_RSA_ENCRYPTION = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";

    /**
     * Verifies the signature and returns the decoded claims.
     * Claim checks (issuer, audience, expiry, ...) happen in Cognito::class.
     *
     * @param  array<string,mixed> $jwks decoded JWKS document
     * @return array<string,mixed> the token payload
     */
    public static function decodeRs256(string $token, array $jwks): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed JWT: expected three dot-separated segments.');
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = self::decodeJsonSegment($encodedHeader, 'header');

        if (($header['alg'] ?? '') !== 'RS256') {
            // Refusing anything but RS256 blocks the classic "alg: none" attack.
            throw new RuntimeException('Unexpected JWT algorithm: ' . var_export($header['alg'] ?? null, true));
        }

        $kid = $header['kid'] ?? '';
        if (!is_string($kid) || $kid === '') {
            throw new RuntimeException('JWT header has no key id (kid).');
        }

        $pem = self::pemForKeyId($jwks, $kid);

        $signature = self::base64UrlDecode($encodedSignature);
        $signedData = $encodedHeader . '.' . $encodedPayload;

        $result = openssl_verify($signedData, $signature, $pem, OPENSSL_ALGO_SHA256);
        if ($result !== 1) {
            throw new RuntimeException('JWT signature verification failed.');
        }

        return self::decodeJsonSegment($encodedPayload, 'payload');
    }

    /** @param array<string,mixed> $jwks */
    private static function pemForKeyId(array $jwks, string $kid): string
    {
        foreach (($jwks['keys'] ?? []) as $key) {
            if (($key['kid'] ?? null) !== $kid) {
                continue;
            }
            if (($key['kty'] ?? '') !== 'RSA') {
                throw new RuntimeException('Unsupported JWKS key type: ' . ($key['kty'] ?? '?'));
            }

            return self::rsaPem(
                self::base64UrlDecode((string) $key['n']),
                self::base64UrlDecode((string) $key['e'])
            );
        }

        throw new RuntimeException("No JWKS key matches kid '{$kid}' (the pool may have rotated keys).");
    }

    /** Build a PEM public key from the raw RSA modulus and exponent. */
    private static function rsaPem(string $modulus, string $exponent): string
    {
        $rsaPublicKey = self::derSequence(
            self::derInteger($modulus) . self::derInteger($exponent)
        );

        $algorithmId = self::derSequence(self::OID_RSA_ENCRYPTION . "\x05\x00"); // ... , NULL

        $spki = self::derSequence($algorithmId . self::derBitString($rsaPublicKey));

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function derSequence(string $body): string
    {
        return "\x30" . self::derLength(strlen($body)) . $body;
    }

    private static function derBitString(string $body): string
    {
        // The leading \x00 is the "number of unused bits in the final byte".
        return "\x03" . self::derLength(strlen($body) + 1) . "\x00" . $body;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        // DER integers are signed, so a high bit needs a 0x00 pad to stay positive.
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /** @return array<string,mixed> */
    private static function decodeJsonSegment(string $segment, string $what): array
    {
        $decoded = json_decode(self::base64UrlDecode($segment), true);
        if (!is_array($decoded)) {
            throw new RuntimeException("JWT {$what} is not valid JSON.");
        }

        return $decoded;
    }

    public static function base64UrlDecode(string $input): string
    {
        $decoded = base64_decode(strtr($input, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('JWT segment is not valid base64url.');
        }

        return $decoded;
    }

    public static function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }
}
