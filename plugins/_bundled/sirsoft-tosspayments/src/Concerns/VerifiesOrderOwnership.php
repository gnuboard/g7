<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;

/**
 * 결제 도중 브라우저가 보낸 주문 상태 변경 요청의 소유자를 확인한다.
 *
 * 결제창 닫힘 보고처럼 PG 서명이 없는 브라우저 요청이 주문을 실패·취소로 바꾸려면, 요청자가
 * 그 주문의 주인임을 **인증으로** 증명해야 한다. 주문자 이메일·전화번호는 주문 확인 화면·알림·
 * 공유로 제3자에게 노출될 수 있어 소유 증명이 되지 못한다 — 그것만 대조하면 연락처를 아는
 * 누구나 결제 진행 중인 남의 주문을 취소할 수 있다.
 *
 * - 회원 주문: 로그인한 본인(Sanctum)만 통과. 비로그인·다른 회원은 거부.
 * - 비회원 주문: 주문 생성 응답에서 발급된 조회 토큰(X-Guest-Order-Token)이 이 주문의 것이어야 통과.
 *
 * 라우트에 optional.sanctum 이 붙어 있어야 Bearer 토큰이 회원으로 인식된다.
 */
trait VerifiesOrderOwnership
{
    /**
     * 요청자가 주문의 소유자인지 확인합니다.
     *
     * @param  Request  $request  브라우저 요청 (Bearer 토큰 또는 X-Guest-Order-Token 헤더)
     * @param  Order  $order  상태를 바꾸려는 주문
     * @param  GuestOrderAuthService  $guestOrderAuthService  비회원 조회 토큰 검증 서비스
     * @return bool 소유자로 확인되면 true
     */
    protected function requestOwnsOrder(Request $request, Order $order, GuestOrderAuthService $guestOrderAuthService): bool
    {
        if ($order->user_id !== null) {
            return Auth::check() && (int) $order->user_id === (int) Auth::id();
        }

        $verified = $guestOrderAuthService->verifyToken(
            $request->header('X-Guest-Order-Token'),
            (string) $order->order_number,
        );

        return $verified !== null && (int) $verified->id === (int) $order->id;
    }
}
