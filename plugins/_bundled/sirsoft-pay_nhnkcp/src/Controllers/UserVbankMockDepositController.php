<?php

// audit:allow api-doc-coverage reason: 룰 면제 주석만 추가 — 요청/응답 계약 불변 (해당 엔드포인트 문서 부재는 사전 상태)

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;

/**
 * 테스트 모드 가상계좌 모의입금처리 정보 제공
 *
 * 조건: 테스트 모드 ON + 가상계좌 결제 + 입금대기 상태
 */
class UserVbankMockDepositController
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    private const KCP_TEST_MOCK_URL = 'https://testadmin.kcp.co.kr/Modules/Noti/TEST_Vcnt_Noti.jsp';

    private const VBANK_NOTIFY_PATH = '/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify';

    public function __construct(
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly OrderProcessingService $orderService,
    ) {}

    /**
     * 테스트 모드 가상계좌 모의입금 처리 화면 데이터
     *
     * 테스트 환경에서 가상계좌 입금을 시뮬레이션할 수 있도록 폼 데이터 반환.
     *
     * @param  Request  $request  인증된 사용자 요청
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 가상계좌 폼 데이터 또는 404
     */
    // audit:allow controller-base-request-injection reason: 본문 입력을 읽지 않음 — user() 만 참조 (검증 대상 필드 없음)
    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];

        if (! ($settings['is_test_mode'] ?? true)) {
            return response()->json(['available' => false]);
        }

        $order = $this->orderService->findByOrderNumber($orderNumber);

        if (! $order) {
            return response()->json(['available' => false]);
        }

        $user = $request->user();

        if (! $user) {
            return response()->json(['available' => false]);
        }

        $isAdmin = method_exists($user, 'isAdmin') && $user->isAdmin();
        $isOwner = (int) $order->user_id === (int) $user->id;

        if (! $isAdmin && ! $isOwner) {
            return response()->json(['available' => false]);
        }

        $payment = $order->payment;

        if (! $payment) {
            return response()->json(['available' => false]);
        }

        $isVbank = $payment->isVirtualAccount();

        // 가상계좌 계좌번호가 발급됐고 아직 입금완료가 아닌 상태
        // (handleVbankIssued는 payment_status를 ready로 유지하므로 isPaid 역확인)
        if (! $isVbank || ! $payment->vbank_number || $payment->isPaid()) {
            return response()->json(['available' => false]);
        }

        // transaction_id(tno)가 없으면 payment_meta에서 fallback
        $meta = $payment->payment_meta ?? [];
        $tradeNo = $payment->transaction_id
            ?? (is_array($meta) ? ($meta['tno'] ?? '') : '');

        return response()->json([
            'available' => true,
            'trade_no' => $tradeNo,
            'account_no' => $payment->vbank_number,
            'notify_url' => url(self::VBANK_NOTIFY_PATH),
            'mock_url' => self::KCP_TEST_MOCK_URL,
            'is_admin_view' => $isAdmin,
        ]);
    }
}
