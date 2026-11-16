<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

// audit:allow api-doc-coverage 요청 파라미터·응답 구조 무변경 — 결제 행 직접 조회를 이커머스 공개 결제 계약으로 바꾼 내부 전환 (#916)

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Plugins\Sirsoft\PayKginicis\Concerns\GuardsOrderScope;

class AdminOrderListController extends AdminBaseController
{
    use GuardsOrderScope;

    /**
     * 컨트롤러를 생성합니다.
     *
     * @param  PaymentGatewayBridge  $bridge  이커머스 결제 공개 계약
     */
    public function __construct(
        private readonly PaymentGatewayBridge $bridge,
    ) {
        parent::__construct();
    }

    /**
     * KG 이니시스 테스트 모드 주문 맵 반환
     *
     * 최근 6 개월 이내 kginicis 결제 주문 중 테스트 결제 건을 { "order_number": true, ... }
     * 형태로 반환한다. 어드민 주문 목록의 결제수단 셀 하단에 "(테스트 결제)" 배지를 표시할
     * 때 사용한다.
     *
     * 판별 우선순위:
     *   1) payment_meta.is_test_mode === true  (콜백에서 저장된 경우)
     *   2) transaction_id 에 "Test" 문자열 포함 (KG 이니시스 테스트 TID 패턴)
     *
     * @return JsonResponse 테스트 모드 주문 맵
     */
    public function testModeMap(): JsonResponse
    {
        $map = [];
        // 배지에는 주문번호, 거래번호, 결제 meta 세 칸만 필요하다 — 결제 행 전체를 올리지 않는 요약 조회를 쓴다
        foreach ($this->bridge->listPaymentBriefsByProvider('kginicis', now()->subMonths(6)) as $payment) {
            if ($payment->orderNumber !== '' && $this->isTestPayment($payment->transactionId, $payment->paymentMeta)) {
                $map[$payment->orderNumber] = true;
            }
        }

        // 범위가 좁혀진 관리자에게는 범위 안 주문번호만 싣는다
        $map = array_intersect_key($map, array_flip($this->orderNumbersWithinScope(array_map('strval', array_keys($map)), 'sirsoft-ecommerce.orders.read')));

        return ResponseHelper::success('common.success', $map);
    }

    /**
     * 결제 건이 테스트 모드 결제인지 판별
     *
     * 판별 우선순위:
     *   1) payment_meta.is_test_mode === true  (콜백에서 명시 저장된 경우)
     *   2) payment_meta.pg_raw_response.mid 가 'SIR' prefix 가 아님
     *      → KG 이니시스 Live MID 는 항상 'SIR' prefix. test MID 는 INIpayTest/iniescrow0 등
     *   3) transaction_id 에 "Test" 문자열 포함  (구식 보조 패턴)
     *
     * @param  string|null  $transactionId  KG 이니시스 TID
     * @param  array<string, mixed>  $meta  payment_meta
     * @return bool 테스트 결제 여부
     */
    private function isTestPayment(?string $transactionId, array $meta): bool
    {
        if ($meta !== []) {
            if (isset($meta['is_test_mode']) && $meta['is_test_mode'] === true) {
                return true;
            }

            $raw = $meta['pg_raw_response'] ?? [];
            if (is_array($raw)) {
                $mid = (string) ($raw['mid'] ?? $raw['MID'] ?? '');
                if ($mid !== '' && ! str_starts_with($mid, 'SIR')) {
                    return true;
                }
            }
        }

        if ($transactionId !== null && $transactionId !== '' && stripos($transactionId, 'Test') !== false) {
            return true;
        }

        return false;
    }
}
