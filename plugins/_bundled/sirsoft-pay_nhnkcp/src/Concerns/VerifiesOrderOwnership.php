<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Concerns;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\OrderRecord;

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
 *   토큰 검증은 이커머스 결제 계약(`OrderAccess::verifyGuestOrderToken`)이 한다.
 *
 * 라우트에 optional.sanctum 이 붙어 있어야 Bearer 토큰이 회원으로 인식된다.
 *
 * 호스트 요건: 이커머스 결제 계약 `PaymentGatewayBridge` 를 `$bridge` 로 주입받는다.
 */
trait VerifiesOrderOwnership
{
    /**
     * 요청자가 주문의 소유자인지 확인합니다.
     *
     * @param  Request  $request  브라우저 요청 (Bearer 토큰 또는 X-Guest-Order-Token 헤더)
     * @param  OrderRecord  $order  상태를 바꾸려는 주문
     * @return bool 소유자로 확인되면 true
     */
    protected function requestOwnsOrder(Request $request, OrderRecord $order): bool
    {
        if ($order->userId !== null) {
            return Auth::check() && $order->userId === (int) Auth::id();
        }

        return $this->bridge->verifyGuestOrderToken($request->header('X-Guest-Order-Token'), $order->orderNumber);
    }

    /**
     * 주문 주인이 아닌 요청의 거부 응답을 만듭니다. (주인이면 null)
     *
     * 판정은 {@see requestOwnsOrder()} 와 같고, 거부 코드만 주문 종류로 가른다.
     *
     * - 회원 주문: 403 — 로그인하지 않았거나 다른 회원이다. 비회원 조회 토큰을 붙여도 회원 판정만 한다.
     * - 비회원 주문: 401 — 조회 토큰이 없거나 만료됐거나 다른 주문의 것이다. 토큰 없이 보낸 401 이라 코어는
     *   로그인 화면으로 옮기지 않고, 결제 핸들러가 「주문 조회에서 다시 결제」 안내를 띄운다(#883).
     *
     * 모바일 승인키 발급의 일반 주문 경로와 클레임 추가결제 경로가 이 한 판정을 쓴다(#855).
     *
     * @param  Request  $request  브라우저 요청 (Bearer 토큰 또는 X-Guest-Order-Token 헤더)
     * @param  OrderRecord  $order  결제할 주문 (클레임 추가결제면 그 결제 건이 딸린 주문)
     * @param  string  $forbiddenMessageKey  회원 주문 거부 문구의 이 플러그인 번역 키(`messages.*`)
     * @return JsonResponse|null 거부 응답 (주인이면 null)
     */
    protected function orderOwnershipRejection(
        Request $request,
        OrderRecord $order,
        string $forbiddenMessageKey,
    ): ?JsonResponse {
        if ($this->requestOwnsOrder($request, $order)) {
            return null;
        }

        if ($order->userId !== null) {
            return ResponseHelper::pluginError('sirsoft-pay_nhnkcp', $forbiddenMessageKey, 403);
        }

        return ResponseHelper::pluginError('sirsoft-pay_nhnkcp', 'messages.responses.guest_verification_failed', 401);
    }
}
