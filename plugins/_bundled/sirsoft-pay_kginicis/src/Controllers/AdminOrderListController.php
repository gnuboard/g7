<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

// audit:allow api-doc-coverage 요청 파라미터·응답 구조 무변경 — 테이블명 리터럴을 모델 파생으로 정리한 내부 리팩토링 (#571)

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;

class AdminOrderListController extends AdminBaseController
{
    public function __construct()
    {
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
        $orders = (new Order)->getTable();
        $payments = (new OrderPayment)->getTable();

        // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
        $rows = DB::table($orders.' as o')
            ->join($payments.' as p', 'p.order_id', '=', 'o.id')
            ->where('p.pg_provider', 'kginicis')
            ->where('p.created_at', '>=', now()->subMonths(6))
            ->select(['o.order_number', 'p.transaction_id', 'p.payment_meta'])
            ->get();

        $map = [];
        foreach ($rows as $row) {
            if ($this->isTestPayment($row->transaction_id, $row->payment_meta)) {
                $map[$row->order_number] = true;
            }
        }

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
     * @param  string|null  $paymentMeta  payment_meta JSON 문자열
     * @return bool 테스트 결제 여부
     */
    private function isTestPayment(?string $transactionId, ?string $paymentMeta): bool
    {
        if ($paymentMeta !== null && $paymentMeta !== '') {
            $meta = json_decode($paymentMeta, true) ?? [];

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
