<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

// audit:allow api-doc-coverage 요청 파라미터·응답 구조 무변경 — 결제 행 조회를 이커머스 공개 결제 계약으로 옮긴 내부 리팩토링 (#916)

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Plugins\Sirsoft\PayNicepayments\Concerns\GuardsOrderScope;

class AdminOrderListController extends AdminBaseController
{
    use GuardsOrderScope;

    /**
     * 관리자 주문 목록 보조 컨트롤러를 생성합니다.
     *
     * @param  PaymentGatewayBridge  $paymentGateway  이커머스 공개 결제 계약
     */
    public function __construct(
        private readonly PaymentGatewayBridge $paymentGateway,
    ) {
        parent::__construct();
    }

    /**
     * 나이스페이먼츠 테스트 모드 주문 맵 반환
     *
     * 최근 6개월 이내 nicepayments(옛 식별자 nicepay 포함) 결제 주문 중 payment_meta.is_test_mode = true 인
     * 주문번호를 { "order_number": true, ... } 맵으로 반환한다. 어드민 주문 목록에
     * 테스트 결제 배지 표시용으로 사용된다.
     *
     * @return JsonResponse 테스트 모드 주문 맵
     */
    public function testModeMap(): JsonResponse
    {
        // 배지에는 주문번호와 결제 meta 만 필요하다 — 결제 행 전체를 올리지 않는 요약 조회를 쓴다
        $payments = $this->paymentGateway->listPaymentBriefsByProvider(['nicepayments', 'nicepay'], now()->subMonths(6), requireMeta: true);

        $map = [];
        foreach ($payments as $payment) {
            if (($payment->paymentMeta['is_test_mode'] ?? false) && $payment->orderNumber !== '') {
                $map[$payment->orderNumber] = true;
            }
        }

        // 범위가 좁혀진 관리자에게는 범위 안 주문번호만 싣는다
        $map = array_intersect_key($map, array_flip($this->orderNumbersWithinScope(array_map('strval', array_keys($map)), 'sirsoft-ecommerce.orders.read')));

        return ResponseHelper::success('common.success', $map);
    }
}
