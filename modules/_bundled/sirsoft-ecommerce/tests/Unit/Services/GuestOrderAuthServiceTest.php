<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 비회원 주문 조회 인증 서비스 Unit 테스트
 *
 * 전화번호 정규화, HMAC 토큰 발급/검증(만료·위조·재사용), 본인 확인을 검증한다.
 */
class GuestOrderAuthServiceTest extends ModuleTestCase
{
    private GuestOrderAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(GuestOrderAuthService::class);
    }

    /**
     * 비회원 주문 + 주문자 전화번호 행 생성 헬퍼
     */
    private function createGuestOrder(string $phone, string $password): Order
    {
        $order = Order::factory()->forGuest()->create([
            'guest_lookup_password_hash' => Hash::make($password),
        ]);

        OrderAddress::create([
            'order_id' => $order->id,
            'address_type' => 'shipping',
            'orderer_name' => '홍길동',
            'orderer_phone' => $phone,
            'orderer_email' => 'guest@test.com',
            'recipient_name' => '홍길동',
            'recipient_phone' => $phone,
            'zipcode' => '12345',
            'address' => '서울시 강남구',
            'address_detail' => '101동',
            'country_code' => 'KR',
        ]);

        return $order->fresh();
    }

    // ========================================================================
    // 전화번호 정규화
    // ========================================================================

    public function test_normalize_phone_strips_non_digits(): void
    {
        $this->assertSame('01012345678', $this->service->normalizePhone('010-1234-5678'));
        $this->assertSame('01012345678', $this->service->normalizePhone('010 1234 5678'));
    }

    public function test_normalize_phone_converts_country_code_82(): void
    {
        $this->assertSame('01012345678', $this->service->normalizePhone('+82 10-1234-5678'));
        $this->assertSame('01012345678', $this->service->normalizePhone('821012345678'));
    }

    public function test_normalize_phone_empty_returns_empty(): void
    {
        $this->assertSame('', $this->service->normalizePhone(null));
        $this->assertSame('', $this->service->normalizePhone('---'));
    }

    // ========================================================================
    // 본인 확인 + 토큰 발급
    // ========================================================================

    public function test_authenticate_success_issues_token(): void
    {
        $order = $this->createGuestOrder('010-1234-5678', 'guest12');

        $result = $this->service->authenticate($order->order_number, '01012345678', 'guest12', '127.0.0.1');

        $this->assertNotNull($result);
        $this->assertSame($order->id, $result['order']->id);
        $this->assertNotEmpty($result['token']);
        $this->assertNotEmpty($result['expires_at']);
    }

    public function test_authenticate_normalizes_phone_before_compare(): void
    {
        // 저장은 하이픈 포함, 입력은 국가번호 형식 → 정규화 후 일치
        $order = $this->createGuestOrder('010-1234-5678', 'guest12');

        $result = $this->service->authenticate($order->order_number, '+82 10 1234 5678', 'guest12', '127.0.0.1');

        $this->assertNotNull($result);
    }

    public function test_authenticate_fails_with_wrong_password(): void
    {
        $order = $this->createGuestOrder('010-1234-5678', 'guest12');

        $this->assertNull($this->service->authenticate($order->order_number, '01012345678', 'wrong99', '127.0.0.1'));
    }

    public function test_authenticate_fails_with_wrong_phone(): void
    {
        $order = $this->createGuestOrder('010-1234-5678', 'guest12');

        $this->assertNull($this->service->authenticate($order->order_number, '01099998888', 'guest12', '127.0.0.1'));
    }

    public function test_authenticate_fails_with_nonexistent_order(): void
    {
        $this->assertNull($this->service->authenticate('NO_SUCH_ORDER', '01012345678', 'guest12', '127.0.0.1'));
    }

    public function test_authenticate_fails_for_member_order(): void
    {
        // 회원 주문(user_id 보유)은 비회원 조회 대상이 아님
        $order = Order::factory()->create(['guest_lookup_password_hash' => Hash::make('guest12')]);
        OrderAddress::create([
            'order_id' => $order->id,
            'address_type' => 'shipping',
            'orderer_name' => '홍길동',
            'orderer_phone' => '010-1234-5678',
            'recipient_name' => '홍길동',
            'recipient_phone' => '010-1234-5678',
            'zipcode' => '12345',
            'address' => '서울',
            'address_detail' => '101',
            'country_code' => 'KR',
        ]);

        $this->assertNull($this->service->authenticate($order->order_number, '01012345678', 'guest12', '127.0.0.1'));
    }

    // ========================================================================
    // 토큰 검증
    // ========================================================================

    public function test_verify_token_success(): void
    {
        $order = $this->createGuestOrder('010-1234-5678', 'guest12');
        $result = $this->service->authenticate($order->order_number, '01012345678', 'guest12', '127.0.0.1');

        $verified = $this->service->verifyToken($result['token'], $order->order_number);

        $this->assertNotNull($verified);
        $this->assertSame($order->id, $verified->id);
    }

    public function test_verify_token_fails_for_other_order(): void
    {
        $order = $this->createGuestOrder('010-1234-5678', 'guest12');
        $result = $this->service->authenticate($order->order_number, '01012345678', 'guest12', '127.0.0.1');

        // 존재하지 않는 주문번호로 재사용 차단 (URL 주문번호로 Order 조회 단계에서 null)
        $this->assertNull($this->service->verifyToken($result['token'], 'OTHER_ORDER'));
    }

    public function test_verify_token_fails_when_reused_for_another_real_guest_order(): void
    {
        // 같은 비밀번호/전화로 두 비회원 주문 생성 후, A 의 토큰을 B 의 URL 에서 사용 시도
        $orderA = $this->createGuestOrder('010-1234-5678', 'guest12');
        $orderB = $this->createGuestOrder('010-1234-5678', 'guest12');
        $result = $this->service->authenticate($orderA->order_number, '01012345678', 'guest12', '127.0.0.1');

        // URL 주문번호로 B 를 조회 → orderId 가 달라 HMAC 재계산 시 서명 불일치
        $this->assertNull($this->service->verifyToken($result['token'], $orderB->order_number));
    }

    public function test_verify_token_fails_when_forged(): void
    {
        $order = $this->createGuestOrder('010-1234-5678', 'guest12');
        $expires = now()->addMinutes(30)->getTimestamp();

        // 위조 서명 토큰 (2-파트 형식 + 64자 hex 형태이지만 서버 비밀로 만든 게 아님)
        $forgedSignature = str_repeat('a', 64);
        $forged = implode('|', [$expires, $forgedSignature]);

        $this->assertNull($this->service->verifyToken($forged, $order->order_number));
    }

    public function test_issued_token_does_not_leak_order_identifiers(): void
    {
        $order = $this->createGuestOrder('010-1234-5678', 'guest12');
        $result = $this->service->authenticate($order->order_number, '01012345678', 'guest12', '127.0.0.1');

        // 토큰 평문에 주문번호/주문ID/비밀번호 해시 suffix 가 노출되지 않아야 함 (HMAC 입력으로만 사용)
        $this->assertStringNotContainsString((string) $order->order_number, $result['token']);
        $this->assertStringNotContainsString('|'.$order->id.'|', $result['token']);
        $this->assertStringNotContainsString(
            substr((string) $order->fresh()->guest_lookup_password_hash, -10),
            $result['token']
        );

        // 형식: `{expiresTs}|{HMAC-SHA256 hex 64자}` (2-파트)
        $parts = explode('|', $result['token']);
        $this->assertCount(2, $parts);
        $this->assertMatchesRegularExpression('/^\d+$/', $parts[0]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $parts[1]);
    }

    public function test_verify_token_fails_after_guest_lookup_password_changed(): void
    {
        $order = $this->createGuestOrder('010-1234-5678', 'guest12');
        $result = $this->service->authenticate($order->order_number, '01012345678', 'guest12', '127.0.0.1');

        // 관리자 또는 사용자 본인이 조회 비밀번호 재설정 (실제 흐름: AdminOrderController::resetGuestLookupPassword)
        $order->update(['guest_lookup_password_hash' => Hash::make('newpass99')]);

        // 기존 토큰은 HMAC 입력의 passwordHashSuffix 가 달라져 서명 불일치 → 즉시 무효화
        $this->assertNull($this->service->verifyToken($result['token'], $order->order_number));
    }

    public function test_verify_token_succeeds_when_password_unchanged_within_ttl(): void
    {
        $order = $this->createGuestOrder('010-1234-5678', 'guest12');
        $result = $this->service->authenticate($order->order_number, '01012345678', 'guest12', '127.0.0.1');

        // 비밀번호 변경 없이 5분 경과 (TTL 30분 내)
        $this->travel(5)->minutes();

        $verified = $this->service->verifyToken($result['token'], $order->order_number);
        $this->assertNotNull($verified);
        $this->assertSame($order->id, $verified->id);
    }

    public function test_verify_token_fails_when_expired(): void
    {
        $order = $this->createGuestOrder('010-1234-5678', 'guest12');
        $result = $this->service->authenticate($order->order_number, '01012345678', 'guest12', '127.0.0.1');

        // 31분 경과 → 만료
        $this->travel(31)->minutes();

        $this->assertNull($this->service->verifyToken($result['token'], $order->order_number));
    }

    public function test_verify_token_fails_with_malformed_token(): void
    {
        $this->assertNull($this->service->verifyToken(null, 'ORD-1'));
        $this->assertNull($this->service->verifyToken('', 'ORD-1'));
        // 파트 수 위반 (4-파트 구형)
        $this->assertNull($this->service->verifyToken('ord|1|123|sig', 'ORD-1'));
        // 만료시각이 숫자 아님
        $this->assertNull($this->service->verifyToken('notnumber|'.str_repeat('a', 64), 'ORD-1'));
        // 서명 형식 위반 (hex 64자 아님)
        $this->assertNull($this->service->verifyToken('9999999999|short', 'ORD-1'));
        // 서명에 non-hex 문자
        $this->assertNull($this->service->verifyToken('9999999999|'.str_repeat('z', 64), 'ORD-1'));
    }
}
