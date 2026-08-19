<?php

// audit:allow api-doc-coverage reason: 에스크로 배송등록 엔드포인트의 기존 문서 부재(사전 상태) — docgen 재생성은 기존 수기 문서(vbank/transaction-status)를 파괴해 신설 불가. 검증 규칙은 FormRequest·CHANGELOG 에 기록, 문서 신설은 후속 백로그

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayNhnkcp\Concerns\SanitizesPgResponse;
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

    public function __construct(
        private readonly NhnKcpApiService $apiService,
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
        $payment = $this->findEscrowPayment($orderNumber);

        if (! $payment) {
            return ResponseHelper::success('common.success', null);
        }

        // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
        $address = DB::table((new OrderAddress)->getTable().' as a')
            ->join((new Order)->getTable().' as o', 'o.id', '=', 'a.order_id')
            ->where('o.order_number', $orderNumber)
            ->where('a.address_type', 'shipping')
            ->select([
                'a.recipient_name',
                'a.recipient_phone',
                'a.zipcode',
                'a.address',
                'a.address_detail',
            ])
            ->first();

        $meta = $payment->payment_meta ? json_decode($payment->payment_meta, true) : [];
        $escrowDelivery = $meta['escrow_delivery'] ?? null;

        return ResponseHelper::success('common.success', [
            'has_escrow_payment' => true,
            'tno' => $payment->transaction_id,
            'courier_codes' => self::COURIER_CODES,
            'prefill' => [
                'recv_name' => $address?->recipient_name ?? '',
                'recv_tel' => $address?->recipient_phone ?? '',
                'recv_post' => $address?->zipcode ?? '',
                'recv_addr' => trim(($address?->address ?? '').' '.($address?->address_detail ?? '')),
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
        $deliNumb = trim((string) $request->validated('deli_numb'));
        $deliCorp = trim((string) $request->validated('deli_corp'));

        $payment = $this->findEscrowPayment($orderNumber);

        if (! $payment) {
            return ResponseHelper::error('common.failed', 404, null);
        }

        Log::info('KCP: escrow delivery register requested', [
            'order_number' => $orderNumber,
            'tno' => $payment->transaction_id,
            'deli_numb' => $deliNumb,
            'deli_corp' => $deliCorp,
        ]);

        try {
            $pgResponse = $this->apiService->registerEscrowDelivery(
                tno: $payment->transaction_id,
                ordrIdxx: $orderNumber,
                deliNumb: $deliNumb,
                deliCorp: $deliCorp,
            );

            $courierName = self::COURIER_CODES[$deliCorp];
            $meta = $payment->payment_meta ? json_decode($payment->payment_meta, true) : [];
            $meta['escrow_delivery'] = [
                'registered_at' => now()->toDateTimeString(),
                'deli_numb' => $deliNumb,
                'deli_corp' => $deliCorp,
                'courier_name' => $courierName,
                'pg_response_sanitized' => true,
                'pg_response' => $this->sanitizePgResponse($pgResponse, self::ESCROW_DELIVERY_RESPONSE_KEYS),
            ];

            // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
            DB::table((new OrderPayment)->getTable())
                ->where('id', $payment->id)
                ->update([
                    'payment_meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);

            Log::info('KCP: escrow delivery registered', [
                'order_number' => $orderNumber,
                'tno' => $payment->transaction_id,
                'deli_numb' => $deliNumb,
                'courier_name' => $courierName,
            ]);

            return ResponseHelper::success('common.success', [
                'res_cd' => $pgResponse['res_cd'] ?? '0000',
                'deli_numb' => $deliNumb,
                'courier_name' => $courierName,
            ]);

        } catch (\Exception $e) {
            Log::error('KCP: escrow delivery register exception', [
                'order_number' => $orderNumber,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::error('common.failed', 500, [
                'message' => [$e->getMessage()],
            ]);
        }
    }

    private function findEscrowPayment(string $orderNumber): ?object
    {
        // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
        return DB::table((new OrderPayment)->getTable().' as p')
            ->join((new Order)->getTable().' as o', 'o.id', '=', 'p.order_id')
            ->where('o.order_number', $orderNumber)
            ->where('p.pg_provider', 'nhnkcp')
            ->where('p.is_escrow', true)
            ->whereNotNull('p.transaction_id')
            ->select([
                'p.id',
                'p.transaction_id',
                'p.paid_amount_local',
                'p.payment_meta',
            ])
            ->first();
    }
}
