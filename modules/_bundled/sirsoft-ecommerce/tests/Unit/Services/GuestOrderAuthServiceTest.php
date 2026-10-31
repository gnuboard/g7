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

    /**
     * 숫자가 아닌 문자는 떼고 비교한다 — 하이픈·공백 표기 차이로 본인 확인이 막히면 안 된다.
     *
     * @scenario guest_auth_gate=phone_normalization
     *
     * @effects phone_numbers_compare_equal_regardless_of_formatting
     */
    public function test_normalize_phone_strips_non_digits(): void
    {
        $this->assertSame('01012345678', $this->service->normalizePhone('010-1234-5678'));
        $this->assertSame('01012345678', $this->service->normalizePhone('010 1234 5678'));
    }

    /**
     * 국가번호 82 표기를 0 으로 바꿔 같은 번호로 본다.
     *
     * @scenario guest_auth_gate=phone_normalization
     *
     * @effects phone_numbers_compare_equal_regardless_of_formatting
     */
    public function test_normalize_phone_converts_country_code_82(): void
    {
        $this->assertSame('01012345678', $this->service->normalizePhone('+82 10-1234-5678'));
        $this->assertSame('01012345678', $this->service->normalizePhone('821012345678'));
    }

    /**
     * 빈 값은 빈 값으로 둔다 — 빈 번호가 아무 번호와 같아지면 안 된다.
     *
     * @scenario guest_auth_gate=phone_normalization
     *
     * @effects phone_numbers_compare_equal_regardless_of_formatting
     */
    public function test_normalize_phone_empty_returns_empty(): void
    {
        $this->assertSame('', $this->service->normalizePhone(null));
        $this->assertSame('', $this->service->normalizePhone('---'));
    }

    // ========================================================================
    // 본인 확인 + 토큰 발급
    // ========================================================================

    /**
     * 주문번호·전화·비밀번호가 맞으면 토큰을 발급한다.
     *
     * @scenario guest_auth_gate=issue_and_verify
     *
     * @effects token_is_valid_only_for_its_own_order_password_and_lifetime
     */
    public function test_authenticate_success_issues_token(): void
    {
        $order = $this->createGuestOrder('010-1234-5678', 'guest12');

        $result = $this->service->authenticate($order->order_number, '01012345678', 'guest12', '127.0.0.1');

        $this->assertNotNull($result);
        $this->assertSame($order->id, $result['order']->id);
        $this->assertNotEmpty($result['token']);
        $this->assertNotEmpty($result['expires_at']);
    }

    /**
     * 표기가 달라도 같은 번호면 인증된다.
     *
     * @scenario guest_auth_gate=issue_and_verify
     *
     * @effects token_is_valid_only_for_its_own_order_password_and_lifetime
     */
    public function test_authenticate_normalizes_phone_before_compare(): void
    {
        // 저장은 하이픈 포함, 입력은 국가번호 형식 → 정규화 후 일치
        $order = $this->createGuestOrder('010-1234-5678', 'guest12');

        $result = $this->service->authenticate($order->order_number, '+82 10 1234 5678', 'guest12', '127.0.0.1');

        $this->assertNotNull($result);
    }

    /**
     * 비밀번호가 틀리면 발급하지 않는다.
     *
     * @scenario guest_auth_gate=issue_and_verify
     *
     * @effects token_is_valid_only_for_its_own_order_password_and_lifetime
     */
    public function test_authenticate_fails_with_wrong_password(): void
    {
        $order = $this->createGuestOrder('010-1234-5678', 'guest12');

        $this->assertNull($this->service->authenticate($order->order_number, '01012345678', 'wrong99', '127.0.0.1'));
    }

    /**
     * 전화번호가 다르면 발급하지 않는다 — 주문번호만 알아도 뚫리면 안 된다.
     *
     * @scenario guest_auth_gate=issue_and_verify
     *
     * @effects token_is_valid_only_for_its_own_order_password_and_lifetime
     */
    public function test_authenticate_fails_with_wrong_phone(): void
    {
        $order = $this->createGuestOrder('010-1234-5678', 'guest12');

        $this->assertNull($this->service->authenticate($order->order_number, '01099998888', 'guest12', '127.0.0.1'));
    }

    /**
     * 없는 주문에는 발급하지 않는다.
     *
     * @scenario guest_auth_gate=issue_and_verify
     *
     * @effects token_is_valid_only_for_its_own_order_password_and_lifetime
     */
    public function test_authenticate_fails_with_nonexistent_order(): void
    {
        $this->assertNull($this->service->authenticate('NO_SUCH_ORDER', '01012345678', 'guest12', '127.0.0.1'));
    }

    /**
     * 회원 주문은 비회원 조회로 열지 않는다 — 회원 인증을 우회하는 통로가 된다.
     *
     * @scenario guest_auth_gate=issue_and_verify
     *
     * @effects token_is_valid_only_for_its_own_order_password_and_lifetime
     */
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

    /**
     * 정상 토큰은 자기 주문에서 통과한다 (기준선).
     *
     * @scenario guest_auth_gate=issue_and_verify
     *
     * @effects token_is_valid_only_for_its_own_order_password_and_lifetime
     */
    public function test_verify_token_success(): void
    {
        $order = $this->createGuestOrder('010-1234-5678', 'guest12');
        $result = $this->service->authenticate($order->order_number, '01012345678', 'guest12', '127.0.0.1');

        $verified = $this->service->verifyToken($result['token'], $order->order_number);

        $this->assertNotNull($verified);
        $this->assertSame($order->id, $verified->id);
    }

    /**
     * 다른 주문번호에 쓰면 거부된다 — 토큰 하나로 남의 주문을 보면 안 된다.
     *
     * @scenario guest_auth_gate=issue_and_verify
     *
     * @effects token_is_valid_only_for_its_own_order_password_and_lifetime
     */
    public function test_verify_token_fails_for_other_order(): void
    {
        $order = $this->createGuestOrder('010-1234-5678', 'guest12');
        $result = $this->service->authenticate($order->order_number, '01012345678', 'guest12', '127.0.0.1');

        // 존재하지 않는 주문번호로 재사용 차단 (URL 주문번호로 Order 조회 단계에서 null)
        $this->assertNull($this->service->verifyToken($result['token'], 'OTHER_ORDER'));
    }

    /**
     * 실재하는 다른 비회원 주문에 돌려써도 거부된다.
     *
     * @scenario guest_auth_gate=issue_and_verify
     *
     * @effects token_is_valid_only_for_its_own_order_password_and_lifetime
     */
    public function test_verify_token_fails_when_reused_for_another_real_guest_order(): void
    {
        // 같은 비밀번호/전화로 두 비회원 주문 생성 후, A 의 토큰을 B 의 URL 에서 사용 시도
        $orderA = $this->createGuestOrder('010-1234-5678', 'guest12');
        $orderB = $this->createGuestOrder('010-1234-5678', 'guest12');
        $result = $this->service->authenticate($orderA->order_number, '01012345678', 'guest12', '127.0.0.1');

        // URL 주문번호로 B 를 조회 → orderId 가 달라 HMAC 재계산 시 서명 불일치
        $this->assertNull($this->service->verifyToken($result['token'], $orderB->order_number));
    }

    /**
     * 서명이 가짜면 거부된다 (형식만 맞춰도 통과하지 않는다).
     *
     * @scenario guest_auth_gate=issue_and_verify
     *
     * @effects token_is_valid_only_for_its_own_order_password_and_lifetime
     */
    public function test_verify_token_fails_when_forged(): void
    {
        $order = $this->createGuestOrder('010-1234-5678', 'guest12');
        $expires = now()->addMinutes(30)->getTimestamp();

        // 위조 서명 토큰 (2-파트 형식 + 64자 hex 형태이지만 서버 비밀로 만든 게 아님)
        $forgedSignature = str_repeat('a', 64);
        $forged = implode('|', [$expires, $forgedSignature]);

        $this->assertNull($this->service->verifyToken($forged, $order->order_number));
    }

    /**
     * 토큰 평문에 주문번호·주문ID·비밀번호 해시가 노출되지 않는다 — 노출되면 그 자체가 정보 유출이다.
     *
     * @scenario guest_auth_gate=issue_and_verify
     *
     * @effects token_is_valid_only_for_its_own_order_password_and_lifetime
     */
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

    /**
     * 조회 비밀번호를 바꾸면 이전 토큰이 끊긴다 — 비밀번호 변경이 실제로 차단 수단이 된다.
     *
     * @scenario guest_auth_gate=issue_and_verify
     *
     * @effects token_is_valid_only_for_its_own_order_password_and_lifetime
     */
    public function test_verify_token_fails_after_guest_lookup_password_changed(): void
    {
        $order = $this->createGuestOrder('010-1234-5678', 'guest12');
        $result = $this->service->authenticate($order->order_number, '01012345678', 'guest12', '127.0.0.1');

        // 관리자 또는 사용자 본인이 조회 비밀번호 재설정 (실제 흐름: AdminOrderController::resetGuestLookupPassword)
        $order->update(['guest_lookup_password_hash' => Hash::make('newpass99')]);

        // 기존 토큰은 HMAC 입력의 passwordHashSuffix 가 달라져 서명 불일치 → 즉시 무효화
        $this->assertNull($this->service->verifyToken($result['token'], $order->order_number));
    }

    /**
     * 비밀번호를 바꾸지 않았으면 유효시간 안에서는 계속 통한다 (대조군).
     *
     * @scenario guest_auth_gate=issue_and_verify
     *
     * @effects token_is_valid_only_for_its_own_order_password_and_lifetime
     */
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

    /**
     * 유효시간이 지나면 거부된다.
     *
     * @scenario guest_auth_gate=issue_and_verify
     *
     * @effects token_is_valid_only_for_its_own_order_password_and_lifetime
     */
    public function test_verify_token_fails_when_expired(): void
    {
        $order = $this->createGuestOrder('010-1234-5678', 'guest12');
        $result = $this->service->authenticate($order->order_number, '01012345678', 'guest12', '127.0.0.1');

        // 31분 경과 → 만료
        $this->travel(31)->minutes();

        $this->assertNull($this->service->verifyToken($result['token'], $order->order_number));
    }

    /**
     * **진짜 서명은 그대로 두고 만료시각 파트만 미래로 늘린 토큰은 거부된다.**
     *
     * 토큰은 `{expiresTs}|{HMAC}` 2-파트이고 **만료시각이 평문으로 노출된다**
     * (`GuestOrderAuthService` 독블록). 그래서 손에 토큰을 넣은 사람이 가장 먼저 해 볼 일은
     * 서명은 건드리지 않고 **앞의 숫자만 늘려 보는 것**이다. 그것이 통하면 30분짜리
     * 조회 토큰이 **영구 토큰**이 된다 — 공유 PC·유출된 링크에서 남의 주문을 계속 들여다볼 수 있다.
     *
     * 형제 테스트들이 이 자리를 덮지 못한다:
     *
     *   - `test_verify_token_fails_when_forged` 는 **서명 자체가 가짜**(`aaa…`)다.
     *     진짜 서명을 그대로 쓰는 이 공격과 다르다.
     *   - `test_verify_token_fails_when_expired` 는 **토큰을 고치지 않고** 시간을 흘린다.
     *     「시간이 지나면 막힌다」는 보지만 「만료를 고쳐 오면 막힌다」는 보지 않는다.
     *
     * 방어의 근거는 HMAC 입력에 `expiresTs` 가 **포함**된다는 점이다(서명 대상의 일부).
     * 누군가 그 입력에서 만료시각을 빼는 순간 이 테스트가 red 로 선다.
     *
     * 2026-09-16 브라우저 실측으로도 확인했다 — 만료를 1년 뒤로 늘린 토큰은 404 였다
     * (#599 세션2 계획서준수검증 2차 · 계획서 1011).
     *
     * @scenario guest_auth_gate=expiry_tamper
     *
     * @effects extending_the_plaintext_expiry_does_not_extend_the_token
     */
    public function test_verify_token_fails_when_expiry_part_is_extended_with_a_real_signature(): void
    {
        $order = $this->createGuestOrder('010-1234-5678', 'guest12');
        $result = $this->service->authenticate($order->order_number, '01012345678', 'guest12', '127.0.0.1');

        // 전제: 발급된 토큰은 지금 유효하다. 이게 깨지면 아래 red 는 다른 이유일 수 있다.
        $this->assertNotNull(
            $this->service->verifyToken($result['token'], $order->order_number),
            '전제: 방금 발급한 토큰은 유효해야 한다.'
        );

        [$expiresTs, $signature] = explode('|', $result['token']);

        // 서명은 **손대지 않고** 만료시각만 1년 뒤로
        $extended = implode('|', [(int) $expiresTs + 31_536_000, $signature]);

        $this->assertNull(
            $this->service->verifyToken($extended, $order->order_number),
            '만료시각 파트를 늘린 토큰이 통과했습니다 — 30분 조회 토큰이 영구 토큰이 됩니다. '
                .'HMAC 입력에서 만료시각이 빠지면 이렇게 됩니다.'
        );

        // 과거로 당기는 방향도 같이 잠근다(서명 불일치 + 만료 판정 양쪽).
        $this->assertNull(
            $this->service->verifyToken(implode('|', [(int) $expiresTs - 31_536_000, $signature]), $order->order_number),
            '만료시각을 과거로 바꾼 토큰도 거부돼야 합니다.'
        );
    }

    /**
     * 형식이 깨진 토큰은 거부된다 (빈 값·파트 수·숫자 아님·서명 형식).
     *
     * @scenario guest_auth_gate=issue_and_verify
     *
     * @effects token_is_valid_only_for_its_own_order_password_and_lifetime
     */
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
