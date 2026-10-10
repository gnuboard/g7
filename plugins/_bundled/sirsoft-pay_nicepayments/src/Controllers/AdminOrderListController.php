<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

// audit:allow api-doc-coverage 요청 파라미터·응답 구조 무변경 — 테이블명 리터럴을 모델 파생으로 정리한 내부 리팩토링 (#571)

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;

class AdminOrderListController extends AdminBaseController
{
    /**
     * 관리자 주문 목록 보조 컨트롤러를 생성합니다.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 나이스페이먼츠 테스트 모드 주문 맵 반환
     *
     * 최근 6개월 이내 nicepayments 결제 주문 중 payment_meta.is_test_mode = true 인
     * 주문번호를 { "order_number": true, ... } 맵으로 반환한다. 어드민 주문 목록에
     * 테스트 결제 배지 표시용으로 사용된다.
     *
     * @return JsonResponse 테스트 모드 주문 맵
     */
    public function testModeMap(): JsonResponse
    {
        // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
        $rows = DB::table((new Order)->getTable().' as o')
            ->join((new OrderPayment)->getTable().' as p', 'p.order_id', '=', 'o.id')
            ->whereIn('p.pg_provider', ['nicepayments', 'nicepay'])
            ->whereNotNull('p.payment_meta')
            ->where('p.created_at', '>=', now()->subMonths(6))
            ->select(['o.order_number', 'p.payment_meta'])
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $meta = json_decode($row->payment_meta, true) ?? [];
            if ($meta['is_test_mode'] ?? false) {
                $map[$row->order_number] = true;
            }
        }

        return ResponseHelper::success('common.success', $map);
    }
}
