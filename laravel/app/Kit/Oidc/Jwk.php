<?php

namespace App\Kit\Oidc;

/**
 * An RSA public key published as a JWK (`kty` RSA, its modulus `n` and exponent `e`, base64url) made into the PEM that
 * openssl_verify takes: the RSAPublicKey inside a SubjectPublicKeyInfo, DER-encoded. Used by IdToken.
 */
final class Jwk
{
    /** The DER of the rsaEncryption algorithm identifier (OID 1.2.840.113549.1.1.1 with NULL parameters). */
    private const RSA_ALGORITHM = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

    public static function toPem(array $jwk): string
    {
        if (($jwk['kty'] ?? null) !== 'RSA' || ! is_string($jwk['n'] ?? null) || ! is_string($jwk['e'] ?? null)) {
            throw new OidcFailed('Not an RSA public key');
        }
        $rsa = self::sequence(self::integer(self::base64url($jwk['n'])).self::integer(self::base64url($jwk['e'])));
        $spki = self::sequence(self::RSA_ALGORITHM."\x03".self::length(strlen($rsa) + 1)."\x00".$rsa);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    public static function base64url(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($decoded === false) {
            throw new OidcFailed('Not base64url');
        }

        return $decoded;
    }

    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '' || ord($bytes[0]) > 0x7f) {
            $bytes = "\0".$bytes; // a positive INTEGER whose top bit is set takes a leading zero byte
        }

        return "\x02".self::length(strlen($bytes)).$bytes;
    }

    private static function sequence(string $body): string
    {
        return "\x30".self::length(strlen($body)).$body;
    }

    private static function length(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }
        $bytes = ltrim(pack('N', $n), "\0");

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
