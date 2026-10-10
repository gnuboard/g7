<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use App\Services\PluginSettingsService;
use App\Support\SiteUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;

/**
 * 테스트 모드 가상계좌 모의입금처리 정보 제공
 *
 * 조건: 테스트 모드 ON + 가상계좌 결제 + 입금대기 상태
 *
 * 응답은 공통 형식(`ResponseHelper`)이며 값은 `data` 에 담는다. 조건이 맞지 않으면 404 가 아니라
 * `data.available = false` 로 답한다 — 화면은 도구를 감추기만 하면 된다.
 */
class UserVbankMockDepositController extends AuthBaseController
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    private const KCP_TEST_MOCK_URL = 'https://testadmin.kcp.co.kr/Modules/Noti/TEST_Vcnt_Noti.jsp';

    private const VBANK_NOTIFY_PATH = '/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify';

    /**
     * @param  PluginSettingsService  $pluginSettingsService  플러그인 설정 서비스(테스트 모드 판정)
     * @param  OrderProcessingService  $orderService  주문 조회 서비스
     */
    public function __construct(
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly OrderProcessingService $orderService,
    ) {
        parent::__construct();
    }

    /**
     * 테스트 모드 가상계좌 모의입금 처리 화면 데이터
     *
     * 테스트 환경에서 가상계좌 입금을 시뮬레이션할 수 있도록 폼 데이터 반환.
     *
     * @param  Request  $request  인증된 사용자 요청
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 가상계좌 폼 데이터(data) — 조건 불충족이면 data.available=false
     */
    // audit:allow controller-base-request-injection reason: 본문 입력을 읽지 않음 — user() 만 참조 (검증 대상 필드 없음)
    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];

        if (! ($settings['is_test_mode'] ?? true)) {
            return $this->unavailable();
        }

        $order = $this->orderService->findByOrderNumber($orderNumber);

        if (! $order) {
            return $this->unavailable();
        }

        $user = $request->user();

        if (! $user) {
            return $this->unavailable();
        }

        $isAdmin = method_exists($user, 'isAdmin') && $user->isAdmin();
        $isOwner = (int) $order->user_id === (int) $user->id;

        if (! $isAdmin && ! $isOwner) {
            return $this->unavailable();
        }

        $payment = $order->payment;

        if (! $payment) {
            return $this->unavailable();
        }

        $isVbank = $payment->isVirtualAccount();

        // 가상계좌 계좌번호가 발급됐고 아직 입금완료가 아닌 상태
        // (발급 전 결제행 READY 와 발급 뒤 WAITING_DEPOSIT 을 모두 받도록 isPaid 역확인)
        if (! $isVbank || ! $payment->vbank_number || $payment->isPaid()) {
            return $this->unavailable();
        }

        // transaction_id(tno)가 없으면 payment_meta에서 fallback
        $meta = $payment->payment_meta ?? [];
        $tradeNo = $payment->transaction_id
            ?? (is_array($meta) ? ($meta['tno'] ?? '') : '');

        return ResponseHelper::success('common.success', [
            'available' => true,
            'trade_no' => $tradeNo,
            'account_no' => $payment->vbank_number,
            'notify_url' => SiteUrl::absolute(self::VBANK_NOTIFY_PATH),
            'mock_url' => self::KCP_TEST_MOCK_URL,
            'is_admin_view' => $isAdmin,
        ]);
    }

    /**
     * 모의입금을 안내할 수 없는 상태의 응답을 만듭니다.
     *
     * @return JsonResponse data.available=false 응답
     */
    private function unavailable(): JsonResponse
    {
        return ResponseHelper::success('common.success', ['available' => false]);
    }
}
