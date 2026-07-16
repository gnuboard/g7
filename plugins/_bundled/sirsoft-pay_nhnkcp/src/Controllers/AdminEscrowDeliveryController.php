<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\PayNhnkcp\Concerns\SanitizesPgResponse;
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

    /** KCP 에스크로 택배사 코드표 */
    private const COURIER_CODES = [
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
            return ResponseHelper::success('messages.success', null);
        }

        $address = DB::table('ecommerce_order_addresses as a')
            ->join('ecommerce_orders as o', 'o.id', '=', 'a.order_id')
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

        return ResponseHelper::success('messages.success', [
            'has_escrow_payment'  => true,
            'tno'                 => $payment->transaction_id,
            'courier_codes'       => self::COURIER_CODES,
            'prefill'             => [
                'recv_name' => $address?->recipient_name ?? '',
                'recv_tel'  => $address?->recipient_phone ?? '',
                'recv_post' => $address?->zipcode ?? '',
                'recv_addr' => trim(($address?->address ?? '') . ' ' . ($address?->address_detail ?? '')),
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
     * @param  Request  $request  배송 정보 폼
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 등록 결과
     */
    public function register(Request $request, string $orderNumber): JsonResponse
    {
        $deliNumb = trim((string) $request->input('deli_numb', ''));
        $deliCorp = trim((string) $request->input('deli_corp', ''));

        if ($deliNumb === '') {
            return ResponseHelper::error('messages.failed', 422, ['deli_numb' => ['운송장번호를 입력해주세요.']]);
        }

        if ($deliCorp === '' || ! array_key_exists($deliCorp, self::COURIER_CODES)) {
            return ResponseHelper::error('messages.failed', 422, ['deli_corp' => ['택배사를 선택해주세요.']]);
        }

        $payment = $this->findEscrowPayment($orderNumber);

        if (! $payment) {
            return ResponseHelper::error('messages.failed', 404, null);
        }

        Log::info('KCP: escrow delivery register requested', [
            'order_number' => $orderNumber,
            'tno'          => $payment->transaction_id,
            'deli_numb'    => $deliNumb,
            'deli_corp'    => $deliCorp,
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
                'deli_numb'     => $deliNumb,
                'deli_corp'     => $deliCorp,
                'courier_name'  => $courierName,
                'pg_response_sanitized' => true,
                'pg_response'   => $this->sanitizePgResponse($pgResponse, self::ESCROW_DELIVERY_RESPONSE_KEYS),
            ];

            DB::table('ecommerce_order_payments')
                ->where('id', $payment->id)
                ->update([
                    'payment_meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                    'updated_at'   => now(),
                ]);

            Log::info('KCP: escrow delivery registered', [
                'order_number' => $orderNumber,
                'tno'          => $payment->transaction_id,
                'deli_numb'    => $deliNumb,
                'courier_name' => $courierName,
            ]);

            return ResponseHelper::success('messages.success', [
                'res_cd'       => $pgResponse['res_cd'] ?? '0000',
                'deli_numb'    => $deliNumb,
                'courier_name' => $courierName,
            ]);

        } catch (\Exception $e) {
            Log::error('KCP: escrow delivery register exception', [
                'order_number' => $orderNumber,
                'error'        => $e->getMessage(),
            ]);

            return ResponseHelper::error('messages.failed', 500, [
                'message' => [$e->getMessage()],
            ]);
        }
    }

    private function findEscrowPayment(string $orderNumber): ?object
    {
        return DB::table('ecommerce_order_payments as p')
            ->join('ecommerce_orders as o', 'o.id', '=', 'p.order_id')
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
