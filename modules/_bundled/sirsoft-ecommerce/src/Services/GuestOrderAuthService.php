<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;

/**
 * 비회원 주문 조회 인증 서비스
 *
 * 주문번호 + 전화번호 + 조회 비밀번호로 비회원 본인 확인을 수행하고,
 * 성공 시 30분 유효한 HMAC 조회 토큰을 발급한다. 이후 주문 상세/액션은
 * 이 토큰(X-Guest-Order-Token)으로 보호된다.
 *
 * 토큰 형식: `{expiresTs}|{HMAC-SHA256}` (2-파트, 만료시각만 평문 노출)
 *   - HMAC 입력: `{orderNumber}|{orderId}|{expiresTs}|{passwordHashSuffix}` + `config('app.key')`
 *   - 주문번호/주문ID/비밀번호 해시 suffix 는 HMAC 입력으로만 사용되어 토큰 평문에서 비노출
 *   - 검증 시 URL 의 주문번호로 Order 조회 → 현재 비밀번호 해시 suffix 와 HMAC 재계산 비교
 *   - 비밀번호 변경/관리자 재설정 시 suffix 가 바뀌어 기존 토큰 즉시 무효화 (stateless revocation)
 *
 * 보안 정책:
 * - 모든 인증 실패(주문 없음/회원 주문/전화번호 불일치/비밀번호 오류)는 동일하게 처리
 *   (호출 측에서 단일 "주문을 찾을 수 없습니다" 응답)
 * - 토큰은 만료/위조/다른 주문 재사용을 모두 차단
 */
class GuestOrderAuthService
{
    /** 토큰 유효 시간 (분) */
    public const TOKEN_TTL_MINUTES = 30;

    public function __construct(
        private OrderRepositoryInterface $orderRepository
    ) {}

    /**
     * 전화번호를 비교용으로 정규화합니다.
     *
     * 숫자만 남기고, 국가번호 82 로 시작하는 국내 휴대전화는 앞자리를 0 으로 변환합니다.
     * 예: "+82 10-1234-5678" → "01012345678", "010-1234-5678" → "01012345678"
     *
     * @param  string|null  $phone  원본 전화번호
     * @return string 정규화된 전화번호 (숫자 only)
     */
    public function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if ($digits === '') {
            return '';
        }

        // 국가번호 82 로 시작하면 0 으로 치환 (821012345678 → 01012345678)
        if (str_starts_with($digits, '82')) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits;
    }

    /**
     * 비회원 본인 확인 후 조회 토큰을 발급합니다.
     *
     * 인증에 성공하면 주문과 발급 토큰 정보를 반환하고, 실패하면 null 을 반환합니다.
     * 실패 사유(주문 없음/회원 주문/전화번호 불일치/비밀번호 오류)는 구분하지 않습니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $ordererPhone  주문자 전화번호
     * @param  string  $guestLookupPassword  조회 비밀번호 평문
     * @param  string|null  $clientIp  요청 IP (실패 로그 식별용)
     * @return array{order: Order, token: string, expires_at: string}|null 성공 시 토큰 정보, 실패 시 null
     */
    public function authenticate(string $orderNumber, string $ordererPhone, string $guestLookupPassword, ?string $clientIp): ?array
    {
        $normalizedPhone = $this->normalizePhone($ordererPhone);
        $order = $this->orderRepository->findByOrderNumber($orderNumber);

        if (! $this->verifyOwnership($order, $normalizedPhone, $guestLookupPassword)) {
            $this->logFailure('mismatch', $orderNumber, $clientIp);

            return null;
        }

        $expiresAt = Carbon::now()->addMinutes(self::TOKEN_TTL_MINUTES);
        $token = $this->issueToken($order, $expiresAt);

        return [
            'order' => $order,
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * 조회 토큰을 검증하고 대상 주문을 반환합니다.
     *
     * 토큰 형식: `{expiresTs}|{HMAC-SHA256}` (2-파트). 만료/위조/형식 오류 시
     * null 을 반환합니다. 검증 흐름:
     *   1. 토큰 파싱(2-파트) + 만료시각 정수성/만료 여부 확인
     *   2. URL 주문번호로 비회원 주문 조회 (다른 주문 재사용 차단 — 토큰의 HMAC 입력에
     *      주문번호/주문ID 가 포함되므로 다른 주문번호로 조회한 Order 의 ID 로 재계산하면
     *      서명 불일치)
     *   3. HMAC 재계산 후 timing-safe 비교
     *
     * @param  string|null  $token  X-Guest-Order-Token 헤더 값
     * @param  string  $expectedOrderNumber  접근하려는 주문번호 (라우트 파라미터)
     * @return Order|null 검증된 주문, 실패 시 null
     */
    public function verifyToken(?string $token, string $expectedOrderNumber): ?Order
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        $parts = explode('|', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$expiresTs, $signature] = $parts;

        // 만료시각 형식 + 만료 여부 확인
        if (! ctype_digit($expiresTs) || (int) $expiresTs < Carbon::now()->getTimestamp()) {
            return null;
        }

        // 서명 형식 확인 (HMAC-SHA256 hex 64자)
        if (! ctype_xdigit($signature) || strlen($signature) !== 64) {
            return null;
        }

        // URL 의 주문번호로 비회원 주문 조회 (다른 주문 재사용 차단 1차)
        $order = $this->orderRepository->findByOrderNumber($expectedOrderNumber);
        if (! $order || $order->user_id !== null) {
            return null;
        }

        // HMAC 재계산 후 timing-safe 비교. 다른 주문번호로 발급된 토큰은 orderNumber/orderId 가 달라
        // 서명이 일치하지 않으며, 발급 이후 비밀번호가 변경되면 passwordHashSuffix 가 달라져 즉시 무효화된다.
        $expectedSignature = $this->sign(
            $order->order_number,
            (int) $order->id,
            (int) $expiresTs,
            $this->passwordHashSuffix($order)
        );
        if (! hash_equals($expectedSignature, $signature)) {
            return null;
        }

        return $order;
    }

    /**
     * 주문 소유권을 검증합니다 (비회원 주문 + 전화번호 + 비밀번호 일치).
     *
     * @param  Order|null  $order  조회된 주문
     * @param  string  $normalizedPhone  정규화된 입력 전화번호
     * @param  string  $guestLookupPassword  조회 비밀번호 평문
     * @return bool 소유권 일치 여부
     */
    private function verifyOwnership(?Order $order, string $normalizedPhone, string $guestLookupPassword): bool
    {
        // 회원 주문이거나 조회 비밀번호 해시가 없으면 비회원 조회 대상 아님
        if (! $order || $order->user_id !== null || empty($order->guest_lookup_password_hash)) {
            return false;
        }

        // 비밀번호 검증
        if (! Hash::check($guestLookupPassword, $order->guest_lookup_password_hash)) {
            return false;
        }

        // 전화번호 비교 (주문자 전화번호, 동일 정규화 규칙)
        $orderPhone = $this->normalizePhone($order->shippingAddress?->orderer_phone);
        if ($orderPhone === '' || ! hash_equals($orderPhone, $normalizedPhone)) {
            return false;
        }

        return true;
    }

    /**
     * HMAC 조회 토큰을 발급합니다.
     *
     * 토큰 평문에는 만료시각만 노출하고, 주문번호/주문ID/비밀번호 해시 suffix 는
     * HMAC 입력으로만 사용한다. 검증 시 URL 주문번호로 Order 를 조회한 뒤 현재 시점의
     * 비밀번호 해시 suffix 로 HMAC 을 재계산해 비교한다.
     *
     * @param  Order  $order  대상 주문
     * @param  Carbon  $expiresAt  만료시각
     * @return string `{expiresTs}|{signature}` 형식 토큰 (2-파트)
     */
    private function issueToken(Order $order, Carbon $expiresAt): string
    {
        $expiresTs = $expiresAt->getTimestamp();
        $signature = $this->sign(
            $order->order_number,
            (int) $order->id,
            $expiresTs,
            $this->passwordHashSuffix($order)
        );

        return implode('|', [$expiresTs, $signature]);
    }

    /**
     * 토큰 서명을 생성합니다 (앱 키 기반 HMAC-SHA256).
     *
     * @param  string  $orderNumber  주문번호
     * @param  int  $orderId  주문 ID
     * @param  int  $expiresTs  만료 timestamp
     * @param  string  $passwordHashSuffix  조회 비밀번호 해시 suffix (stateless revocation 용)
     * @return string HMAC 서명
     */
    private function sign(string $orderNumber, int $orderId, int $expiresTs, string $passwordHashSuffix): string
    {
        $payload = $orderNumber.'|'.$orderId.'|'.$expiresTs.'|'.$passwordHashSuffix;

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    /**
     * HMAC 입력에 포함될 조회 비밀번호 해시 suffix 를 반환합니다.
     *
     * bcrypt 해시(60자)의 마지막 10자만 사용. HMAC 입력에만 사용되어 외부에 노출되지 않으며,
     * 비밀번호 변경/관리자 재설정 시 suffix 가 바뀌어 기존 토큰의 서명이 자동으로 불일치한다.
     * suffix 가 비어있을 가능성은 verifyOwnership 단계에서 차단되지만, 안전상 빈 문자열을 허용.
     *
     * @param  Order  $order  대상 주문
     * @return string 비밀번호 해시 suffix (최대 10자)
     */
    private function passwordHashSuffix(Order $order): string
    {
        return substr((string) $order->guest_lookup_password_hash, -10);
    }

    /**
     * 조회 실패를 민감값 없이 최소 정보로 기록합니다.
     *
     * @param  string  $reason  실패 사유 코드 (mismatch)
     * @param  string  $orderNumber  주문번호
     * @param  string|null  $clientIp  요청 IP
     */
    private function logFailure(string $reason, string $orderNumber, ?string $clientIp): void
    {
        Log::info('Guest order lookup failed', [
            'reason' => $reason,
            'order_number' => $orderNumber,
            'ip' => $clientIp,
        ]);
    }
}
