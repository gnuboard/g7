<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Concerns;

use Illuminate\Cookie\CookieJar;
use Symfony\Component\HttpFoundation\Cookie;

trait IssuesReceiptCookie
{
    public const RECEIPT_COOKIE_NAME = 'nhnkcp_receipt_token';
    private const RECEIPT_COOKIE_TTL_MINUTES = 5;

    protected function queueReceiptCookie(string $orderNumber): void
    {
        $expiresTs = time() + (self::RECEIPT_COOKIE_TTL_MINUTES * 60);
        $signature = $this->signReceiptToken($orderNumber, $expiresTs);
        $tokenValue = $orderNumber.'|'.$expiresTs.'|'.$signature;

        /** @var CookieJar $jar */
        $jar = app(CookieJar::class);

        $jar->queue(new Cookie(
            name: self::RECEIPT_COOKIE_NAME,
            value: $tokenValue,
            expire: $expiresTs,
            path: '/',
            domain: null,
            secure: request()->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        ));
    }

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

        if ($orderNumber !== $expectedOrderNumber) {
            return false;
        }

        if (! ctype_digit($expiresTs) || (int) $expiresTs < time()) {
            return false;
        }

        if (! ctype_xdigit($signature) || strlen($signature) !== 64) {
            return false;
        }

        return hash_equals($this->signReceiptToken($orderNumber, (int) $expiresTs), $signature);
    }

    private function signReceiptToken(string $orderNumber, int $expiresTs): string
    {
        return hash_hmac('sha256', $orderNumber.'|'.$expiresTs, (string) config('app.key', ''));
    }
}
