<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Plugins\Sirsoft\PayNicepayments\Concerns\IssuesReceiptCookie;
use Plugins\Sirsoft\PayNicepayments\Concerns\ResolvesEasyPayDisplay;

class UserReceiptController extends PublicBaseController
{
    use IssuesReceiptCookie;
    use ResolvesEasyPayDisplay;

    private const RECEIPT_BASE_URL = 'https://npg.nicepay.co.kr/issue/IssueLoader.do';

    /**
     * @param  GuestOrderAuthService  $guestOrderAuthService  비회원 조회 토큰 검증 서비스
     */
    public function __construct(
        private readonly GuestOrderAuthService $guestOrderAuthService,
    ) {
        parent::__construct();
    }

    /**
     * 사용자 마이페이지 영수증 정보 조회
     *
     * 결제 영수증 URL(신용카드)과 현금영수증 URL(현금/계좌이체)을 반환한다.
     * receipt_url 이 비어있으면 transaction_id 로 NicePay IssueLoader URL 을 동적 생성.
     * 테스트 모드 결제는 is_test_mode=true 플래그로 표시되어 UI 에서 안내 가능.
     *
     * @param  Request  $request  회원 또는 비회원 영수증 요청
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse receipt_url / payment_receipt_url / cash_receipt_url / is_test_mode 또는 404
     */
    // audit:allow controller-base-request-injection reason: 본문 입력을 읽지 않음 — user()/비회원 영수증 쿠키만 참조 (검증 대상 필드 없음)
    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $user = $request->user();
        // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
        $query = DB::table((new OrderPayment)->getTable().' as p')
            ->join((new Order)->getTable().' as o', 'o.id', '=', 'p.order_id')
            ->where('o.order_number', $orderNumber)
            ->where('p.pg_provider', 'nicepayments');

        if ($user) {
            $query->where('o.user_id', $user->id);
        } else {
            $order = $this->guestOrderAuthService->verifyToken($request->header('X-Guest-Order-Token'), $orderNumber);

            if ($order) {
                $query->whereNull('o.user_id')->where('o.id', $order->id);
            } elseif ($this->verifyReceiptCookie($request->cookie(self::RECEIPT_COOKIE_NAME), $orderNumber)) {
                $query->whereNull('o.user_id')
                    ->where('o.id', function ($sub) use ($orderNumber) {
                        $sub->select('id')->from((new Order)->getTable())->where('order_number', $orderNumber);
                    });
            } else {
                return ResponseHelper::notFound();
            }
        }

        $payment = $query
            ->select([
                'p.transaction_id',
                'p.receipt_url',
                'p.payment_meta',
                'p.payment_method',
                'p.embedded_pg_provider',
            ])
            ->first();

        if (! $payment) {
            return ResponseHelper::notFound();
        }

        $receiptUrl = $payment->receipt_url;
        if (! $receiptUrl && $payment->transaction_id) {
            $receiptUrl = self::RECEIPT_BASE_URL.'?type=0&TID='.rawurlencode($payment->transaction_id);
        }

        $cashReceiptUrl = null;
        $isTestMode = false;
        $display = $this->resolvePaymentDisplay($payment);
        if ($payment->payment_meta) {
            $meta = json_decode($payment->payment_meta, true);
            $rcptTid = $meta['rcpt_tid'] ?? ($meta['pg_raw_response']['RcptTID'] ?? null);
            if ($rcptTid) {
                $cashReceiptUrl = self::RECEIPT_BASE_URL.'?type=1&TID='.rawurlencode($rcptTid);
            }
            $isTestMode = (bool) ($meta['is_test_mode'] ?? false);
        }

        // 마이페이지 「결제 영수증」 링크가 첫 출시부터 열어 온 주소(type=2). 레이아웃에 결제사 외부 주소를 적으면
        // 편집기 저장 게이트(외부 URL 금지)가 거절하므로 서버가 내려준다. receipt_url(매출전표, type=0)과는
        // 다른 화면이 쓰는 다른 값이라 서로 대신하지 않는다.
        $paymentReceiptUrl = $payment->transaction_id
            ? self::RECEIPT_BASE_URL.'?type=2&TID='.rawurlencode($payment->transaction_id)
            : null;

        return ResponseHelper::success('common.success', [
            'receipt_url' => $receiptUrl,
            'payment_receipt_url' => $paymentReceiptUrl,
            'cash_receipt_url' => $cashReceiptUrl,
            'is_test_mode' => $isTestMode,
            'payment_method_label' => $display['payment_method_label'],
            'payment_method_display_label' => $display['payment_method_display_label'],
            'selected_payment_method' => $display['selected_payment_method'],
            'embedded_pg_provider' => $display['embedded_pg_provider'],
            'embedded_pg_provider_label' => $display['embedded_pg_provider_label'],
        ]);
    }
}
