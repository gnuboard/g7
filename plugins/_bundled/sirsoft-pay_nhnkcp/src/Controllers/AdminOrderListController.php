<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\PayNhnkcp\Concerns\ResolvesEasyPayDisplay;

class AdminOrderListController extends AdminBaseController
{
    use ResolvesEasyPayDisplay;

    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    private bool $isTest;

    public function __construct(PluginSettingsService $pluginSettingsService)
    {
        parent::__construct();
        $settings = $pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $this->isTest = $settings['is_test_mode'] ?? true;
    }

    /**
     * NHN KCP 테스트 모드 주문 맵 반환
     *
     * 현재 플러그인이 테스트 모드이면 최근 6개월 nhnkcp 전체 주문을 테스트로 간주한다.
     * 라이브 모드이면 payment_meta.is_test_mode = true 인 주문만 반환한다.
     *
     * @return JsonResponse 테스트 모드 주문 맵 { "order_number": true, ... }
     */
    public function testModeMap(): JsonResponse
    {
        $query = DB::table('ecommerce_orders as o')
            ->join('ecommerce_order_payments as p', 'p.order_id', '=', 'o.id')
            ->where('p.pg_provider', 'nhnkcp')
            ->where('p.created_at', '>=', now()->subMonths(6));

        if ($this->isTest) {
            $orderNumbers = $query->pluck('o.order_number');
            $map = array_fill_keys($orderNumbers->all(), true);
        } else {
            $rows = $query
                ->whereNotNull('p.payment_meta')
                ->select(['o.order_number', 'p.payment_meta'])
                ->get();

            $map = [];
            foreach ($rows as $row) {
                $meta = json_decode($row->payment_meta, true) ?? [];
                if ($meta['is_test_mode'] ?? false) {
                    $map[$row->order_number] = true;
                }
            }
        }

        return ResponseHelper::success('messages.success', $map);
    }

    /**
     * NHN KCP 간편결제 표시 맵 반환
     *
     * 관리자 주문목록은 코어 OrderPaymentResource 의 기본 payment_method_label 을 사용하므로,
     * 플러그인 데이터소스로 간편결제 원 결제수단 표시명을 보강한다.
     *
     * @return JsonResponse 주문번호별 표시 맵
     */
    public function easyPayDisplayMap(): JsonResponse
    {
        $rows = DB::table('ecommerce_orders as o')
            ->join('ecommerce_order_payments as p', 'p.order_id', '=', 'o.id')
            ->where('p.pg_provider', 'nhnkcp')
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
