<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Concerns;

use Illuminate\Cookie\CookieJar;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * KG 이니시스 결제 완료 직후 사용자 브라우저에 단기 영수증 토큰 쿠키를 발급/검증한다.
 *
 * 비회원 주문은 `X-Guest-Order-Token` 헤더가 globalHeaders 자동 주입에 의존하지만,
 * 결제 직후 sessionStorage 가 비어 있거나 브라우저 캐시가 stale 한 환경에서는
 * 영수증 조회 호출이 실패할 수 있다. PG callback 이 결제 성공 시점에 HttpOnly
 * cookie 를 1회 발급해두면 다음 호출(영수증 조회) 에 자동 전송되어 sessionStorage
 * 의존을 제거한다.
 *
 * 토큰 형식: `{orderNumber}|{expiresTs}|{HMAC-SHA256}` (3-파트).
 * 만료/위조/형식 오류 / 주문번호 mismatch 시 null 반환.
 */
trait IssuesReceiptCookie
{
    public const RECEIPT_COOKIE_NAME = 'kginicis_receipt_token';
    private const RECEIPT_COOKIE_TTL_MINUTES = 5;

    /**
     * 결제 완료 직후 호출하여 영수증 토큰 cookie 를 큐에 추가한다.
     * Laravel 의 응답 미들웨어가 다음 응답에 Set-Cookie 헤더로 첨부.
     *
     * @param  string  $orderNumber  결제 완료 주문번호
     */
    protected function queueReceiptCookie(string $orderNumber): void
    {
        $expiresTs = time() + (self::RECEIPT_COOKIE_TTL_MINUTES * 60);
        $signature = $this->signReceiptToken($orderNumber, $expiresTs);
        $tokenValue = $orderNumber.'|'.$expiresTs.'|'.$signature;

        /** @var CookieJar $jar */
        $jar = app(CookieJar::class);

        // HttpOnly + SameSite=Lax + Secure(HTTPS) — XSS 방어 + same-site 흐름 한정
        $cookie = new Cookie(
            name: self::RECEIPT_COOKIE_NAME,
            value: $tokenValue,
            expire: $expiresTs,
            path: '/',
            domain: null,
            secure: request()->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );

        $jar->queue($cookie);
    }

    /**
     * 영수증 토큰 cookie 를 검증하여 주문번호 일치 여부를 반환한다.
     *
     * @param  string|null  $cookieValue  요청에서 추출한 cookie 값
     * @param  string  $expectedOrderNumber  검증할 주문번호
     * @return bool 유효한 토큰 여부
     */
    protected function verifyReceiptCookie(?string $cookieValue, string $expectedOrderNumber): bool
    {
        if (! is_string($cookieValue) || $cookieValue === '') {
            return false;
        }

        $parts = explode('|', $cookieValue);
        if (count($parts) !== 3) {
            return false;
        }

        [$orderNumber, $expiresTs, $signature] = $parts;

        // 다른 주문 cookie 재사용 차단
        if ($orderNumber !== $expectedOrderNumber) {
            return false;
        }

        // 만료시각 형식 + 만료 여부
        if (! ctype_digit($expiresTs) || (int) $expiresTs < time()) {
            return false;
        }

        // 서명 형식 (HMAC-SHA256 hex 64자)
        if (! ctype_xdigit($signature) || strlen($signature) !== 64) {
            return false;
        }

        $expectedSignature = $this->signReceiptToken($orderNumber, (int) $expiresTs);

        return hash_equals($expectedSignature, $signature);
    }

    private function signReceiptToken(string $orderNumber, int $expiresTs): string
    {
        $payload = $orderNumber.'|'.$expiresTs;
        $key = (string) config('app.key', '');

        return hash_hmac('sha256', $payload, $key);
    }
}
