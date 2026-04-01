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
     * @return array
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.after_login' => [
                'method' => 'handle',
                'priority' => 20,
            ],
        ];
    }

    /**
     * 로그인 성공 시 비회원 장바구니를 회원 계정으로 병합합니다.
     *
     * @param mixed ...$args 첫 번째: User 객체, 두 번째: 로그인 컨텍스트 배열
     * @return void
     */
    public function handle(...$args): void
    {
        $user = $args[0] ?? null;

        if (! $user instanceof User) {
            return;
        }

        $cartKey = request()->header('X-Cart-Key');

        if (! $cartKey) {
            return;
        }

        try {
            $mergedCount = $this->cartService->mergeGuestCartToUser($cartKey, $user->id);

            if ($mergedCount > 0) {
                Log::info('장바구니 병합 완료', [
                    'user_id' => $user->uuid,
                    'cart_key' => $cartKey,
                    'merged_count' => $mergedCount,
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
