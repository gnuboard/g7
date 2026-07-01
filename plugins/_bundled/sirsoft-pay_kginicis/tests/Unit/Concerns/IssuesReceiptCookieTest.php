<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Concerns;

use Plugins\Sirsoft\PayKginicis\Concerns\IssuesReceiptCookie;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;
use ReflectionClass;

/**
 * 영수증 cookie HMAC 검증 로직 단위 테스트.
 *
 * HTTP 통합(callback → Set-Cookie → 후속 호출에서 cookie 자동 첨부) 검증은
 * Playwright 시나리오로 다룬다 — Laravel 의 EncryptCookies 미들웨어와 PHPUnit
 * HTTP 테스트 환경의 cookie 처리 흐름이 production 과 일부 다르기 때문.
 */
class IssuesReceiptCookieTest extends PluginTestCase
{
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        // IssuesReceiptCookie 는 trait — 익명 클래스로 인스턴스화
        $this->subject = new class {
            use IssuesReceiptCookie {
                verifyReceiptCookie as public;
            }
        };
    }

    public function test_verify_succeeds_with_fresh_token_matching_order_number(): void
    {
        $orderNumber = 'ORD-RECEIPT-12345';
        $cookieValue = $this->buildToken($orderNumber, time() + 300);

        $this->assertTrue($this->subject->verifyReceiptCookie($cookieValue, $orderNumber));
    }

    public function test_verify_fails_when_cookie_missing(): void
    {
        $this->assertFalse($this->subject->verifyReceiptCookie(null, 'ORD-RECEIPT-99999'));
        $this->assertFalse($this->subject->verifyReceiptCookie('', 'ORD-RECEIPT-99999'));
    }

    public function test_verify_fails_when_token_format_invalid(): void
    {
        // 3-파트 미충족
        $this->assertFalse($this->subject->verifyReceiptCookie('ORD-X|123', 'ORD-X'));
        // 만료시각이 숫자 아님
        $this->assertFalse($this->subject->verifyReceiptCookie('ORD-X|abc|' . str_repeat('0', 64), 'ORD-X'));
        // 서명이 hex 아님
        $this->assertFalse($this->subject->verifyReceiptCookie('ORD-X|' . (time() + 300) . '|invalid_sig', 'ORD-X'));
    }

    public function test_verify_fails_when_token_expired(): void
    {
        $orderNumber = 'ORD-RECEIPT-EXPIRED';
        $expiredCookie = $this->buildToken($orderNumber, time() - 60);

        $this->assertFalse($this->subject->verifyReceiptCookie($expiredCookie, $orderNumber));
    }

    public function test_verify_fails_when_order_number_mismatched(): void
    {
        $tokenFor = 'ORD-RECEIPT-AAA';
        $accessing = 'ORD-RECEIPT-BBB';
        $cookieValue = $this->buildToken($tokenFor, time() + 300);

        // cookie 의 payload 가 다른 주문번호이므로 reject
        $this->assertFalse($this->subject->verifyReceiptCookie($cookieValue, $accessing));
    }

    public function test_verify_fails_when_signature_tampered(): void
    {
        $orderNumber = 'ORD-RECEIPT-TAMPER';
        $expiresTs = time() + 300;
        // 정상 서명 대신 0으로 가득 찬 위조 서명
        $tampered = $orderNumber . '|' . $expiresTs . '|' . str_repeat('0', 64);

        $this->assertFalse($this->subject->verifyReceiptCookie($tampered, $orderNumber));
    }

    private function buildToken(string $orderNumber, int $expiresTs): string
    {
        $payload = $orderNumber . '|' . $expiresTs;
        $signature = hash_hmac('sha256', $payload, (string) config('app.key', ''));

        return $orderNumber . '|' . $expiresTs . '|' . $signature;
    }
}
