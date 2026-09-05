<?php

namespace Plugins\Sirsoft\PayNhnkcp\Concerns;

use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 모바일 가상계좌 콜백을 인증된 결제 세션에 묶는 일회성 state.
 *
 * KCP 모바일(SmartPhone Pay)은 가상계좌 계좌번호를 서버-서버 승인이 아니라 브라우저
 * 평문 POST 로만 전달한다. 그래서 콜백만으로는 그 값이 KCP 에서 온 것인지 확인할 수
 * 없고, 주문번호만 아는 제3자가 위조 계좌를 영속시킬 수 있었다 (KVE-2026-2019).
 *
 * 방어는 KCP 가 passthrough 파라미터를 그대로 되돌려주는 성질을 이용한다 —
 * 소유자 인증을 거친 승인키 발급 시점에 nonce 를 만들어 주문에 저장하고 `param_opt_2`
 * 로 실어 보낸 뒤, 콜백에서 되돌아온 값과 대조한다. `param_opt_1` 은 간편결제 수단
 * 식별자가 이미 점유하고 있으므로 `param_opt_2` 를 쓴다.
 *
 * 발급 지점은 `auth:sanctum` 이라 nonce 는 주문 소유자의 세션에 완전히 묶인다.
 * 공격자는 피해자의 nonce 를 알 수 없다.
 */
trait BindsMobileVbankSession
{
    /**
     * payment_meta 안의 state 키
     */
    private const MOBILE_VBANK_STATE_KEY = 'mobile_vbank_state';

    /**
     * 모바일 가상계좌 세션 nonce 를 발급해 주문에 저장합니다.
     *
     * 저장에 실패하면 null 을 반환한다 — 호출부는 nonce 없이 결제창을 열지 않는다
     * (검증 불가능한 콜백을 만들지 않기 위해서다).
     *
     * @param  Order  $order  대상 주문
     * @return string|null 발급된 nonce, 저장 실패 시 null
     */
    protected function issueMobileVbankState(Order $order): ?string
    {
        $payment = $order->payment;
        if (! $payment || ! $payment->exists) {
            return null;
        }

        $nonce = bin2hex(random_bytes(16));

        try {
            $meta = $payment->payment_meta ?? [];
            $meta = is_array($meta) ? $meta : [];
            $meta[self::MOBILE_VBANK_STATE_KEY] = [
                'nonce' => $nonce,
                'issued_at' => now()->toIso8601String(),
            ];

            $payment->payment_meta = $meta;
            $payment->save();
        } catch (\Exception $e) {
            Log::error('KCP: failed to persist mobile vbank session state', [
                'order_number' => $order->order_number,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $nonce;
    }

    /**
     * 콜백으로 되돌아온 `param_opt_2` 가 저장된 nonce 와 일치하는지 확인합니다.
     *
     * 저장된 state 가 없거나 값이 다르면 false 다. 일치 여부는 timing-safe 비교로
     * 판정한다.
     *
     * @param  Order  $order  대상 주문
     * @param  string|null  $echoedState  콜백이 되돌려준 param_opt_2
     * @return bool 일치 여부
     */
    protected function mobileVbankStateMatches(Order $order, ?string $echoedState): bool
    {
        if (! is_string($echoedState) || $echoedState === '') {
            return false;
        }

        $payment = $order->payment;
        if (! $payment || ! $payment->exists) {
            return false;
        }

        $meta = $payment->payment_meta ?? [];
        $meta = is_array($meta) ? $meta : [];
        $stored = $meta[self::MOBILE_VBANK_STATE_KEY]['nonce'] ?? null;

        if (! is_string($stored) || $stored === '') {
            return false;
        }

        return hash_equals($stored, $echoedState);
    }
}
