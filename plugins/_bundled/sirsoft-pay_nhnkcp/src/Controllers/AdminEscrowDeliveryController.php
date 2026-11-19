<?php

// audit:allow api-doc-coverage reason: 에스크로 배송등록 엔드포인트의 기존 문서 부재(사전 상태) — docgen 재생성은 기존 수기 문서(vbank/transaction-status)를 파괴해 신설 불가. 검증 규칙은 FormRequest·CHANGELOG 에 기록, 문서 신설은 후속 백로그

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Helpers\ResponseHelper;
use App\Helpers\TimezoneHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\MetaEncoding;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentFilters;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Plugins\Sirsoft\PayNhnkcp\Concerns\GuardsOrderScope;
use Plugins\Sirsoft\PayNhnkcp\Concerns\SanitizesPgResponse;
use Plugins\Sirsoft\PayNhnkcp\Exceptions\NhnKcpRejectedException;
use Plugins\Sirsoft\PayNhnkcp\Http\Requests\EscrowDeliveryRegisterRequest;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;

/**
 * KCP 에스크로 배송등록 관리자 컨트롤러
 *
 * 에스크로 결제 후 상품 발송 시 KCP에 운송장번호를 등록합니다.
 * CLI mod_type=STE1 방식으로 배송정보를 전달합니다.
 */
class AdminEscrowDeliveryController extends AdminBaseController
{
    use GuardsOrderScope;
    use SanitizesPgResponse;

    private const ESCROW_DELIVERY_RESPONSE_KEYS = [
        'res_cd',
        'res_msg',
        'tno',
        'deli_numb',
        'deli_corp',
    ];

    /** 택배사 코드 → 택배사명 매핑 (KCP 공식 코드표 — EscrowDeliveryRegisterRequest 의 허용값 SSoT) */
    public const COURIER_CODES = [
        '04' => 'CJ대한통운',
        '05' => '한진택배',
        '06' => '로젠택배',
        '08' => '롯데택배',
        '09' => '우체국택배',
        '11' => '경동택배',
        '13' => '일양로지스',
        '14' => '합동택배',
        '20' => '드림택배',
        '23' => '천일택배',
        '26' => '건영택배',
        '40' => '기타',
    ];

    /**
     * 의존성을 주입받습니다.
     *
     * @param  NhnKcpApiService  $apiService  NHN KCP API 서비스
     * @param  PaymentGatewayBridge  $bridge  이커머스 결제 계약 (결제 행·배송지 조회, 결제 meta 기록)
     */
    public function __construct(
        private readonly NhnKcpApiService $apiService,
        private readonly PaymentGatewayBridge $bridge,
    ) {
        parent::__construct();
    }

    /**
     * 배송등록 폼 데이터 반환 (주소 자동완성 및 기등록 배송 이력 포함)
     */
    /**
     * 에스크로 배송 등록 폼 초기 데이터 조회
     *
     * 어드민 에스크로 배송 등록 화면에서 주문 정보 + 기본 배송지/수령인을 미리 채울 수 있도록 반환.
     *
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 폼 초기 데이터 또는 404
     */
    public function formData(string $orderNumber): JsonResponse
    {
        if ($denied = $this->orderScopeDenial($orderNumber, 'sirsoft-ecommerce.orders.read')) {
            return $denied;
        }

        $payment = $this->findEscrowPayment($orderNumber);

        if (! $payment) {
            return ResponseHelper::success('common.success', null);
        }

        $address = $this->bridge->getShippingAddress($orderNumber);

        $escrowDelivery = $payment->paymentMeta['escrow_delivery'] ?? null;
        // 등록 시각은 UTC 로 저장돼 있다 — 결제조회 화면에는 보는 사람 시간대로 바꿔 내보낸다(저장값은 그대로)
        if (is_array($escrowDelivery) && is_string($escrowDelivery['registered_at'] ?? null) && $escrowDelivery['registered_at'] !== '') {
            try {
                $escrowDelivery['registered_at'] = TimezoneHelper::toUserDateTimeString(Carbon::parse($escrowDelivery['registered_at'], 'UTC'));
            } catch (\Throwable) {
                // 해석할 수 없는 값은 원문 그대로 둔다
            }
        }

        return ResponseHelper::success('common.success', [
            'has_escrow_payment' => true,
            'tno' => $payment->transactionId,
            'courier_codes' => self::COURIER_CODES,
            'prefill' => [
                'recv_name' => $address?->recipientName ?? '',
                'recv_tel' => $address?->recipientPhone ?? '',
                'recv_post' => $address?->zipcode ?? '',
                'recv_addr' => trim(($address?->address ?? '').' '.($address?->addressDetail ?? '')),
            ],
            'registered_delivery' => $escrowDelivery,
        ]);
    }

    /**
     * KCP에 배송정보 등록 (CLI mod_type=STE1)
     */
    /**
     * 에스크로 배송 등록 API 호출
     *
     * KCP 에스크로 API 로 배송 정보(택배사/송장번호/수령인 등) 등록. 등록 완료 시 구매확정 안내 자동 발송.
     *
     * 형식·길이·택배사 코드 검증은 EscrowDeliveryRegisterRequest 가 담당한다
     * (종전 인라인 한글 하드코딩 메시지를 다국어 키로 이관).
     *
     * @param  EscrowDeliveryRegisterRequest  $request  배송 정보 폼
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 등록 결과
     */
    public function register(EscrowDeliveryRegisterRequest $request, string $orderNumber): JsonResponse
    {
        if ($denied = $this->orderScopeDenial($orderNumber, 'sirsoft-ecommerce.orders.update')) {
            return $denied;
        }

        $deliNumb = trim((string) $request->validated('deli_numb'));
        $deliCorp = trim((string) $request->validated('deli_corp'));

        $payment = $this->findEscrowPayment($orderNumber);

        if (! $payment) {
            return ResponseHelper::error('common.failed', 404, null);
        }

        Log::info('KCP: escrow delivery register requested', [
            'order_number' => $orderNumber,
            'tno' => $payment->transactionId,
            'deli_numb' => $deliNumb,
            'deli_corp' => $deliCorp,
        ]);

        try {
            $pgResponse = $this->apiService->registerEscrowDelivery(
                tno: (string) $payment->transactionId,
                ordrIdxx: $orderNumber,
                deliNumb: $deliNumb,
                deliCorp: $deliCorp,
            );

            $courierName = self::COURIER_CODES[$deliCorp];

            // 결제 meta 의 escrow_delivery 만 바꾼다. 원문 바이트(한글 비이스케이프)와 수정 시각 갱신은 종전 저장과 같다.
            $this->bridge->mergePaymentMeta($orderNumber, 'nhnkcp', [
                'escrow_delivery' => [
                    // audit:allow datetime-display-user-timezone reason: UTC 저장 — 표시 시 변환
                    'registered_at' => now()->toDateTimeString(),
                    'deli_numb' => $deliNumb,
                    'deli_corp' => $deliCorp,
                    'courier_name' => $courierName,
                    'pg_response_sanitized' => true,
                    'pg_response' => $this->sanitizePgResponse($pgResponse, self::ESCROW_DELIVERY_RESPONSE_KEYS),
                ],
            ], MetaEncoding::UnescapedUnicode);

            Log::info('KCP: escrow delivery registered', [
                'order_number' => $orderNumber,
                'tno' => $payment->transactionId,
                'deli_numb' => $deliNumb,
                'courier_name' => $courierName,
            ]);

            return ResponseHelper::success('common.success', [
                'res_cd' => $pgResponse['res_cd'] ?? '0000',
                'deli_numb' => $deliNumb,
                'courier_name' => $courierName,
            ]);

        } catch (\Exception $e) {
            // 원문은 서버 로그에 남기고, 응답 errors 에는 운영자가 봐도 되는 사유만 싣는다(#858).
            Log::error('KCP: escrow delivery register exception', [
                'order_number' => $orderNumber,
                'exception_class' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::error('common.failed', 500, [
                'message' => [$this->failureReason($e)],
            ]);
        }
    }

    /**
     * 배송 등록 실패 응답에 실을 사유를 고릅니다.
     *
     * KCP 가 돌려준 거절 사유(res_msg)는 운영자가 조치에 쓰는 정보라 그대로 싣는다. CLI 실행 실패 문구에는
     * 서버 절대 경로가, DB 예외 원문에는 SQL·DB 접속 정보가 담기므로 운영 모드에서는 일반 문구로 바꾼다
     * (원문은 호출 측이 서버 로그에 남긴다). 디버그 모드는 코어 관례(SafeExceptionMessage)대로 원문을 둔다.
     *
     * @param  \Throwable  $e  처리 중 잡은 예외
     * @return string 응답에 실을 사유
     */
    private function failureReason(\Throwable $e): string
    {
        if ($e instanceof NhnKcpRejectedException || config('app.debug')) {
            return $e->getMessage();
        }

        return __('sirsoft-pay_nhnkcp::messages.errors.escrow_delivery_failed');
    }

    /**
     * 주문번호의 NHN KCP 에스크로 결제 레코드를 찾습니다.
     *
     * 거래번호는 null 만 거른다(빈 문자열 행은 종전처럼 남긴다).
     *
     * @param  string  $orderNumber  주문번호
     * @return PaymentRecord|null 결제 레코드 (없으면 null)
     */
    private function findEscrowPayment(string $orderNumber): ?PaymentRecord
    {
        return $this->bridge->findPaymentByOrderNumber('nhnkcp', $orderNumber, new PaymentFilters(escrow: true, hasTransactionId: true));
    }
}
