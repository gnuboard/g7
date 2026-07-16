<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Services;

use Illuminate\Http\Request;

class CbtCheckoutTokenService
{
    private const VERSION = 1;

    private const DEFAULT_TTL_SECONDS = 600;

    public function issue(
        string $oid,
        int $price,
        string $buyerEmail,
        string $buyerPhone,
        Request $request,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ): string {
        $payload = [
            'v' => self::VERSION,
            'oid' => $oid,
            'price' => $price,
            'buyer_email_hash' => $this->contextHash($this->normalizeEmail($buyerEmail)),
            'buyer_phone_hash' => $this->contextHash($this->normalizePhone($buyerPhone)),
            'ip_hash' => $this->contextHash((string) $request->ip()),
            'ua_hash' => $this->contextHash(substr((string) $request->userAgent(), 0, 255)),
            'exp' => time() + max(60, $ttlSeconds),
            'nonce' => bin2hex(random_bytes(16)),
        ];

        $payloadSegment = $this->base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signatureSegment = $this->base64UrlEncode(
            hash_hmac('sha256', $payloadSegment, $this->signingKey(), true)
        );

        return $payloadSegment . '.' . $signatureSegment;
    }

    public function verify(
        string $token,
        string $oid,
        int $price,
        string $buyerEmail,
        string $buyerPhone,
        Request $request,
    ): bool {
        if ($token === '' || strlen($token) > 4096) {
            return false;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return false;
        }

        [$payloadSegment, $signatureSegment] = $parts;
        $expectedSignature = $this->base64UrlEncode(
            hash_hmac('sha256', $payloadSegment, $this->signingKey(), true)
        );
        if (! hash_equals($expectedSignature, $signatureSegment)) {
            return false;
        }

        $payloadJson = $this->base64UrlDecode($payloadSegment);
        if ($payloadJson === null) {
            return false;
        }

        $payload = json_decode($payloadJson, true);
        if (! is_array($payload)) {
            return false;
        }

        return ($payload['v'] ?? null) === self::VERSION
            && (string) ($payload['oid'] ?? '') === $oid
            && (int) ($payload['price'] ?? 0) === $price
            && (int) ($payload['exp'] ?? 0) >= time()
            && hash_equals((string) ($payload['buyer_email_hash'] ?? ''), $this->contextHash($this->normalizeEmail($buyerEmail)))
            && hash_equals((string) ($payload['buyer_phone_hash'] ?? ''), $this->contextHash($this->normalizePhone($buyerPhone)))
            && hash_equals((string) ($payload['ip_hash'] ?? ''), $this->contextHash((string) $request->ip()))
            && hash_equals((string) ($payload['ua_hash'] ?? ''), $this->contextHash(substr((string) $request->userAgent(), 0, 255)));
    }

    private function normalizeEmail(string $value): string
    {
        return strtolower(trim($value));
    }

    private function normalizePhone(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }

    private function contextHash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->signingKey());
    }

    private function signingKey(): string
    {
        return hash('sha256', (string) config('app.key') . '|sirsoft-pay_kginicis|cbt-checkout-token', true);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }
}
