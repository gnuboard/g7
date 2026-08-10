<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Services;

use Illuminate\Http\Request;

class CbtCheckoutTokenService
{
    private const VERSION = 1;

    private const DEFAULT_TTL_SECONDS = 600;

    /**
     * @var int 토큰 수명 하한(초) — 발급 즉시 만료된 토큰이 나가는 것을 막는 방어값이며
     *          설정으로 조절되는 정책 상한이 아니다.
     */
    private const MIN_TTL_SECONDS = 60;

    /**
     * 결제 컨텍스트를 묶은 서명 토큰을 발급합니다.
     *
     * @param  string  $oid  주문번호
     * @param  int  $price  결제 금액
     * @param  string  $buyerEmail  구매자 이메일 (해시로만 저장)
     * @param  string  $buyerPhone  구매자 연락처 (해시로만 저장)
     * @param  Request  $request  발급 요청 (IP·UA 해시 수집용)
     * @param  int  $ttlSeconds  토큰 수명(초). MIN_TTL_SECONDS 미만이면 하한으로 올림
     * @return string `payload.signature` 형식의 토큰
     */
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
            'exp' => time() + max(self::MIN_TTL_SECONDS, $ttlSeconds),
            'nonce' => bin2hex(random_bytes(16)),
        ];

        $payloadSegment = $this->base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signatureSegment = $this->base64UrlEncode(
            hash_hmac('sha256', $payloadSegment, $this->signingKey(), true)
        );

        return $payloadSegment.'.'.$signatureSegment;
    }

    /**
     * 토큰이 현재 결제 컨텍스트와 일치하고 만료되지 않았는지 검증합니다.
     *
     * @param  string  $token  검증할 토큰
     * @param  string  $oid  주문번호
     * @param  int  $price  결제 금액
     * @param  string  $buyerEmail  구매자 이메일
     * @param  string  $buyerPhone  구매자 연락처
     * @param  Request  $request  검증 요청 (IP·UA 대조용)
     * @return bool 서명·컨텍스트·만료가 모두 유효하면 true
     */
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
        return hash('sha256', (string) config('app.key').'|sirsoft-pay_kginicis|cbt-checkout-token', true);
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
