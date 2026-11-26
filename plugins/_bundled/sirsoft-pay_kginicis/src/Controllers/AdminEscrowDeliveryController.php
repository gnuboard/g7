<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

// audit:allow api-doc-coverage 요청 파라미터·응답 구조 무변경 — 결제 행·배송지 직접 조회를 이커머스 공개 결제 계약으로 바꾼 내부 전환 

use App\Helpers\ResponseHelper;
use App\Helpers\TimezoneHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\MetaEncoding;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentFilters;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Plugins\Sirsoft\PayKginicis\Concerns\GuardsOrderScope;
use Plugins\Sirsoft\PayKginicis\Concerns\SanitizesPgResponse;
use Plugins\Sirsoft\PayKginicis\Concerns\SerializesPaymentCallbacks;
use Plugins\Sirsoft\PayKginicis\Http\Requests\EscrowDeliveryRegisterRequest;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

/**
 * KG 이니시스 에스크로 배송등록 관리자 컨트롤러
 *
 * PC/모바일 공통 엔드포인트 사용 (INIAPI v1 /api/v1/escrow)
 * 메뉴얼: https://manual.inicis.com/pay/escrow_pc.html#dlv
 */
class AdminEscrowDeliveryController extends AdminBaseController
{
    use GuardsOrderScope;
    use SanitizesPgResponse;
    use SerializesPaymentCallbacks;

    /** 배송등록 주문 잠금 문맥 (SerializesPaymentCallbacks 잠금 키 구분자) */
    public const LOCK_CONTEXT = 'escrow-delivery';

    /** 택배사 코드 → 택배사명 매핑 (KG 이니시스 공식 코드표 — EscrowDeliveryRegisterRequest 의 허용값 SSoT) */
    public const COURIER_CODES = [
        'hanjin' => '한진택배',
        'cjgls' => 'CJ대한통운',
        'loge' => '롯데택배',
        'epost' => '우체국택배',
        'lotte' => '롯데글로벌로지스',
        'kdexp' => '경동택배',
        'cvs' => '편의점택배',
        'ilyang' => '일양로지스',
        'chunil' => '천일택배',
        'cvsnet' => 'CVSnet편의점',
        'daesin' => '대신택배',
        'kunyoung' => '건영택배',
        'gsilogis' => 'GSI Express',
        'etc' => '기타',
    ];

    /** 에스크로 배송등록 PG 응답 저장 허용 필드 */
    private const ESCROW_DELIVERY_RESPONSE_KEYS = [
        'resultCode',
        'resultMsg',
        'tid',
        'TID',
        'oid',
        'OID',
        'mid',
        'MID',
        'type',
        'report',
    ];

    /**
     * 의존성을 주입받습니다.
     *
     * @param  KgInicisApiService  $apiService  KG이니시스 API 서비스
     * @param  PaymentGatewayBridge  $bridge  이커머스 결제 공개 계약
     */
    public function __construct(
        private readonly KgInicisApiService $apiService,
        private readonly PaymentGatewayBridge $bridge,
    ) {
        parent::__construct();
    }

    /**
     * 에스크로 배송 등록 폼에 채울 결제·배송지·등록 이력을 돌려줍니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 폼 데이터 (에스크로 결제가 없으면 data null)
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

        // payment_meta에서 이력 추출
        $meta = $payment->paymentMeta;
        // 시각은 UTC 로 저장돼 있다 — 결제조회 화면에는 보는 사람 시간대로 바꿔 내보낸다
        $escrowDelivery = $this->withViewerTime($meta['escrow_delivery'] ?? null, 'registered_at');
        $escrowConfirm = $this->withViewerTime($meta['escrow_confirm'] ?? null, 'confirmed_at');
        $denyConfirmed = isset($meta['escrow_deny_confirm']);

        return ResponseHelper::success('common.success', [
            'has_escrow_payment' => true,
            'tid' => $payment->transactionId,
            'price' => (int) round($payment->paidAmountLocal),
            'courier_codes' => self::COURIER_CODES,
            'prefill' => [
                'recvName' => $address?->recipientName ?? '',
                'recvTel' => $address?->recipientPhone ?? '',
                'recvPost' => $address?->zipcode ?? '',
                'recvAddr' => trim(($address?->address ?? '').' '.($address?->addressDetail ?? '')),
            ],
            'registered_delivery' => $escrowDelivery,
            'escrow_confirm' => $escrowConfirm,
            'deny_confirmed' => $denyConfirmed,
        ]);
    }

    /**
     * register
     *
     * 형식·길이·택배사 코드 검증은 EscrowDeliveryRegisterRequest 가 담당한다
     * (종전 인라인 한글 하드코딩 메시지를 다국어 키로 이관).
     *
     * 같은 주문의 배송등록은 주문 단위 잠금(`escrow-delivery`)으로 한 번에 하나만 처리한다. 더블클릭·재시도로
     * 두 요청이 겹치면 둘 다 "아직 등록 전"을 보고 PG 에 신규 등록(I)을 두 번 보내기 때문이다. 결제 메타는
     * 잠금을 잡은 뒤 다시 읽어, 앞선 요청이 이미 등록했으면 신규 등록(I)을 409 로 거절한다(변경 U 는 허용).
     * 잠금을 기다리다 시간이 다 되면 다른 요청이 처리 중이므로 409(`messages.errors.escrow_in_progress`)를 돌려준다.
     *
     * @param  EscrowDeliveryRegisterRequest  $request  배송 등록 폼
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 배송 등록 결과
     */
    public function register(EscrowDeliveryRegisterRequest $request, string $orderNumber): JsonResponse
    {
        if ($denied = $this->orderScopeDenial($orderNumber, 'sirsoft-ecommerce.orders.update')) {
            return $denied;
        }

        try {
            $lock = $this->acquireOrderCallbackLock(self::LOCK_CONTEXT, $orderNumber);
        } catch (LockTimeoutException) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.errors.escrow_in_progress', 409, [
                'message' => [__('sirsoft-pay_kginicis::messages.errors.escrow_in_progress')],
            ]);
        }

        try {
            return $this->registerUnderLock($request, $orderNumber);
        } finally {
            $this->releaseOrderCallbackLock($lock);
        }
    }

    /**
     * 주문 잠금을 잡은 상태에서 결제 메타를 다시 읽고 배송등록을 PG 에 전송·기록합니다.
     *
     * @param  EscrowDeliveryRegisterRequest  $request  배송 등록 폼
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 등록 결과 응답
     */
    private function registerUnderLock(EscrowDeliveryRegisterRequest $request, string $orderNumber): JsonResponse
    {
        $invoice = trim((string) $request->validated('invoice'));
        $exCode = trim((string) $request->validated('ex_code'));

        $payment = $this->findEscrowPayment($orderNumber);

        if (! $payment) {
            return ResponseHelper::error('common.failed', 404, null);
        }

        $report = $request->validated('report') ?? 'I';

        // 잠금 안에서 다시 읽은 메타 기준 — 이미 등록된 건에 신규 등록(I)을 또 보내지 않는다 (변경 U 는 정상 경로)
        $currentMeta = $payment->paymentMeta;
        if ($report === 'I' && ! empty($currentMeta['escrow_delivery'])) {
            return ResponseHelper::pluginError('sirsoft-pay_kginicis', 'messages.errors.escrow_delivery_already_registered', 409, [
                'message' => [__('sirsoft-pay_kginicis::messages.errors.escrow_delivery_already_registered')],
            ]);
        }
        $charge = $request->validated('charge') ?? 'SH';
        $price = (int) round($payment->paidAmountLocal);
        $exName = self::COURIER_CODES[$exCode];

        // 수신자 주소: 요청 우선, 없으면 DB 조회
        $recvName = trim((string) ($request->validated('recv_name') ?? ''));
        $recvTel = trim((string) ($request->validated('recv_tel') ?? ''));
        $recvPost = trim((string) ($request->validated('recv_post') ?? ''));
        $recvAddr = trim((string) ($request->validated('recv_addr') ?? ''));

        if ($recvName === '' || $recvTel === '' || $recvAddr === '') {
            $address = $this->bridge->getShippingAddress($orderNumber);

            $recvName = $recvName ?: ($address?->recipientName ?? '');
            $recvTel = $recvTel ?: ($address?->recipientPhone ?? '');
            $recvPost = $recvPost ?: ($address?->zipcode ?? '');
            $recvAddr = $recvAddr ?: trim(($address?->address ?? '').' '.($address?->addressDetail ?? ''));
        }

        Log::info('KG Inicis: escrow delivery register requested', [
            'order_number' => $orderNumber,
            'tid' => $payment->transactionId,
            'invoice' => $invoice,
            'ex_code' => $exCode,
            'report' => $report,
        ]);

        try {
            // 에스크로 결제는 반드시 에스크로 MID/키 사용
            $this->apiService->useEscrowCredentials(true);

            $pgResponse = $this->apiService->registerEscrowDelivery([
                'tid' => $payment->transactionId,
                'oid' => $orderNumber,
                'price' => $price,
                'report' => $report,
                'invoice' => $invoice,
                'registName' => trim((string) ($request->validated('regist_name') ?? '')),
                'exCode' => $exCode,
                'exName' => $exName,
                'charge' => $charge,
                // 배송등록일은 PG 업무 날짜 — 한국 시간(D7)
                // audit:allow datetime-display-user-timezone reason: PG 업무 날짜(invoiceDay) — Asia/Seoul 고정(D7)
                'invoiceDay' => CarbonImmutable::now('Asia/Seoul')->format('Y-m-d H:i:s'),
                'sendName' => trim((string) ($request->validated('send_name') ?? '')),
                'sendTel' => trim((string) ($request->validated('send_tel') ?? '')),
                'sendPost' => trim((string) ($request->validated('send_post') ?? '')),
                'sendAddr1' => trim((string) ($request->validated('send_addr') ?? '')),
                'recvName' => $recvName,
                'recvTel' => $recvTel,
                'recvPost' => $recvPost,
                'recvAddr' => $recvAddr,
            ]);

            $resultCode = $pgResponse['resultCode'] ?? '';
            $sanitizedPgResponse = $this->sanitizePgResponse($pgResponse, self::ESCROW_DELIVERY_RESPONSE_KEYS);

            if ($resultCode !== '00') {
                Log::warning('KG Inicis: escrow delivery register failed', [
                    'order_number' => $orderNumber,
                    'result_code' => $resultCode,
                    'result_msg' => $pgResponse['resultMsg'] ?? '',
                    'pg_response' => $sanitizedPgResponse,
                ]);

                return ResponseHelper::error('common.failed', 502, [
                    'message' => [($pgResponse['resultMsg'] ?? '') !== '' ? $pgResponse['resultMsg'] : __('sirsoft-pay_kginicis::messages.escrow.delivery_rejected')],
                ]);
            }

            // payment_meta에 배송등록 정보 저장 — 결제 행 잠금 아래 현재 meta 에 병합한다(원문 유니코드 그대로)
            $this->bridge->mergePaymentMeta($orderNumber, 'kginicis', [
                'pg_response_sanitized' => true,
                'escrow_delivery' => [
                    // audit:allow datetime-display-user-timezone reason: UTC 저장 — 표시 시 변환
                    'registered_at' => now()->toDateTimeString(),
                    'report' => $report,
                    'invoice' => $invoice,
                    'ex_code' => $exCode,
                    'ex_name' => $exName,
                    'charge' => $charge,
                    'recv_name' => $recvName,
                    'recv_addr' => $recvAddr,
                    'pg_response' => $sanitizedPgResponse,
                ],
            ], MetaEncoding::UnescapedUnicode);

            Log::info('KG Inicis: escrow delivery registered', [
                'order_number' => $orderNumber,
                'tid' => $payment->transactionId,
                'invoice' => $invoice,
                'ex_name' => $exName,
            ]);

            return ResponseHelper::success('common.success', [
                'result_code' => $resultCode,
                'result_msg' => $pgResponse['resultMsg'] ?? 'OK',
                'invoice' => $invoice,
                'ex_name' => $exName,
            ]);

        } catch (\Exception $e) {
            // PG 거절(resultCode != 00)은 위에서 resultMsg 로 답했다. 여기 오는 것은 통신 실패·HTTP 오류·DB 예외라
            // 원문(API 주소·cURL 문구·SQL)은 서버 로그에만 남기고 응답에는 일반 문구를 싣는다.
            Log::error('KG Inicis: escrow delivery register exception', [
                'order_number' => $orderNumber,
                'exception_class' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::error('common.failed', 500, [
                // 디버그 모드는 코어 관례(SafeExceptionMessage)대로 원문을 둔다
                'message' => [config('app.debug') ? $e->getMessage() : __('sirsoft-pay_kginicis::messages.escrow.delivery_failed')],
            ]);
        }
    }

    /**
     * 메타 항목의 UTC 시각 필드를 보는 사람 시간대 문자열로 바꾼 사본을 돌려줍니다. (저장값은 바꾸지 않는다)
     *
     * @param  mixed  $entry  메타 항목 (배열이 아니면 그대로)
     * @param  string  $key  시각 필드 이름
     * @return mixed 변환한 사본
     */
    private function withViewerTime(mixed $entry, string $key): mixed
    {
        if (! is_array($entry) || empty($entry[$key]) || ! is_string($entry[$key])) {
            return $entry;
        }

        try {
            $entry[$key] = TimezoneHelper::toUserDateTimeString(Carbon::parse($entry[$key], 'UTC'));
        } catch (\Throwable) {
            // 해석할 수 없는 값은 원문 그대로 둔다
        }

        return $entry;
    }

    /**
     * 주문번호의 KG이니시스 에스크로 결제 레코드를 찾습니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return PaymentRecord|null 결제 레코드 (없으면 null)
     */
    private function findEscrowPayment(string $orderNumber): ?PaymentRecord
    {
        return $this->bridge->findPaymentByOrderNumber(
            'kginicis',
            $orderNumber,
            new PaymentFilters(escrow: true, hasTransactionId: true),
        );
    }
}
