<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\PayNicepayments\Concerns\ResolvesEasyPayDisplay;

class AdminOrderListController extends AdminBaseController
{
    use ResolvesEasyPayDisplay;

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
        $rows = DB::table('ecommerce_orders as o')
            ->join('ecommerce_order_payments as p', 'p.order_id', '=', 'o.id')
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

        return ResponseHelper::success('messages.success', $map);
    }

    /**
     * 나이스페이먼츠 간편결제 표시 맵 반환
     *
     * 관리자 주문목록은 코어 결제 리소스의 기본 결제수단 라벨을 사용하므로,
     * 플러그인 데이터소스로 실제 간편결제 표시명을 보강한다.
     *
     * @return JsonResponse 주문번호별 표시 맵
     */
    public function easyPayDisplayMap(): JsonResponse
    {
        $rows = DB::table('ecommerce_orders as o')
            ->join('ecommerce_order_payments as p', 'p.order_id', '=', 'o.id')
            ->whereIn('p.pg_provider', ['nicepayments', 'nicepay'])
            ->where('p.created_at', '>=', now()->subMonths(6))
            ->select([
                'o.order_number',
                'p.payment_method',
                'p.embedded_pg_provider',
                'p.payment_meta',
            ])
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $display = $this->resolvePaymentDisplay($row);
            if (($display['embedded_pg_provider_label'] ?? null) === null) {
                continue;
            }

            $map[$row->order_number] = [
                'payment_method_label' => $display['payment_method_label'],
                'payment_method_display_label' => $display['payment_method_display_label'],
                'embedded_pg_provider' => $display['embedded_pg_provider'],
                'embedded_pg_provider_label' => $display['embedded_pg_provider_label'],
            ];
        }

        return ResponseHelper::success('messages.success', $map);
    }
}
