<?php

// audit:allow api-doc-coverage reason: 룰 면제 주석만 추가 — 요청/응답 계약 불변 (해당 엔드포인트 문서 부재는 사전 상태)

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Plugins\Sirsoft\PayNhnkcp\Concerns\GuardsOrderScope;

class AdminOrderListController extends AdminBaseController
{
    use GuardsOrderScope;

    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    private bool $isTest;

    /**
     * 관리자 주문 목록 보조 컨트롤러를 생성하고 현재 테스트 모드 여부를 읽습니다.
     *
     * @param  PluginSettingsService  $pluginSettingsService  플러그인 설정 서비스
     * @param  PaymentGatewayBridge  $bridge  이커머스 결제 계약 (이 PG 결제 행 목록·권한 범위)
     */
    public function __construct(
        PluginSettingsService $pluginSettingsService,
        private readonly PaymentGatewayBridge $bridge,
    ) {
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
        // 최근 6개월 이 PG 결제 행(라이브 모드는 결제 meta 가 있는 행만) — 배지에 필요한 세 칸만 싣는 요약 조회를 쓴다
        $payments = array_filter(
            $this->bridge->listPaymentBriefsByProvider('nhnkcp', now()->subMonths(6), requireMeta: ! $this->isTest),
            fn ($payment): bool => $payment->orderNumber !== '',
        );

        if ($this->isTest) {
            $map = array_fill_keys(array_map(fn ($payment) => $payment->orderNumber, $payments), true);
        } else {
            $map = [];
            foreach ($payments as $payment) {
                if ($payment->paymentMeta['is_test_mode'] ?? false) {
                    $map[(string) $payment->orderNumber] = true;
                }
            }
        }

        // 범위가 좁혀진 관리자에게는 범위 안 주문번호만 싣는다
        $map = array_intersect_key($map, array_flip($this->orderNumbersWithinScope(array_map('strval', array_keys($map)), 'sirsoft-ecommerce.orders.read')));

        return ResponseHelper::success('common.success', $map);
    }
}
