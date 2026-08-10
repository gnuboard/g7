<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Services\CartService;

/**
 * 로그인 시 비회원 장바구니를 회원 계정으로 병합하는 리스너
 */
class MergeCartOnLoginListener implements HookListenerInterface
{
    public function __construct(
        protected CartService $cartService
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 이름 => 실행 설정(method/priority/sync) 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.after_login' => [
                'method' => 'handle',
                'priority' => 20,
                // 비회원 장바구니 식별자는 전송 헤더로만 온다. 큐 컨텍스트(HookContextCapture)는
                // IP·UA·path 만 복원하고 임의 헤더는 나르지 않으므로, 큐로 넘기면 워커에서
                // 헤더가 null 이 되어 병합이 조용히 건너뛰어진다 — 기본 큐 드라이버가 database 인
                // 설치에서는 로그인해도 담아둔 장바구니가 사라진다. 로그인 응답 안에서 끝나야 하는
                // 작업이므로 동기 실행한다(실패는 아래 try/catch 가 흡수해 로그인은 지키다).
                'sync' => true,
            ],
        ];
    }

    /**
     * 로그인 성공 시 비회원 장바구니를 회원 계정으로 병합합니다.
     *
     * @param  mixed  ...$args  첫 번째: User 객체, 두 번째: 로그인 컨텍스트 배열
     */
    public function handle(...$args): void
    {
        $user = $args[0] ?? null;

        if (! $user instanceof User) {
            return;
        }

        // 코어 로그인 훅 페이로드는 이커머스를 모르므로(모듈 키를 코어 계약에 넣지 않는다)
        // 비회원 장바구니 식별자는 전송 헤더가 유일한 출처다. 형식은 병합 엔드포인트
        // (MergeGuestCartRequest)와 같은 규칙으로 아래에서 검증한다.
        // audit:allow listener-formrequest-bypass reason: 코어 훅이 전달하지 않는 전송 계층 식별자 — 아래에서 형식 검증
        $cartKey = request()->header('X-Cart-Key');

        if (! is_string($cartKey) || ! preg_match('/^ck_[a-zA-Z0-9]{32}$/', $cartKey)) {
            return;
        }

        try {
            $mergedCount = $this->cartService->mergeGuestCartToUser($cartKey, $user->id);
            $adjustments = $this->cartService->getLastMergeAdjustments();

            if ($mergedCount > 0) {
                Log::info('장바구니 병합 완료', [
                    'user_id' => $user->uuid,
                    'cart_key' => $cartKey,
                    'merged_count' => $mergedCount,
                ]);
            }

            // 로그인 경로의 병합은 응답에 결과를 실을 자리가 없다. 수량이 상한까지 줄었다면
            // 최소한 운영자가 추적할 수 있도록 조정 내역을 남긴다 (조용한 클램프 금지).
            if ($adjustments !== []) {
                Log::warning('장바구니 병합 중 수량이 상한으로 조정됨', [
                    'user_id' => $user->uuid,
                    'cart_key' => $cartKey,
                    'adjustments' => $adjustments,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('장바구니 병합 실패', [
                'user_id' => $user->uuid,
                'cart_key' => $cartKey,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
