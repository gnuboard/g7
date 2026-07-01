<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\PayKginicis\Concerns\SanitizesPgResponse;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

/**
 * KG 이니시스 에스크로 배송등록 관리자 컨트롤러
 *
 * PC/모바일 공통 엔드포인트 사용 (INIAPI v1 /api/v1/escrow)
 * 메뉴얼: https://manual.inicis.com/pay/escrow_pc.html#dlv
 */
class AdminEscrowDeliveryController extends AdminBaseController
{
    use SanitizesPgResponse;

    /** 택배사 코드 → 택배사명 매핑 (KG 이니시스 공식 코드표) */
    private const COURIER_CODES = [
        'hanjin'   => '한진택배',
        'cjgls'    => 'CJ대한통운',
        'loge'     => '롯데택배',
        'epost'    => '우체국택배',
        'lotte'    => '롯데글로벌로지스',
        'kdexp'    => '경동택배',
        'cvs'      => '편의점택배',
        'ilyang'   => '일양로지스',
        'chunil'   => '천일택배',
        'cvsnet'   => 'CVSnet편의점',
        'daesin'   => '대신택배',
        'kunyoung'  => '건영택배',
        'gsilogis' => 'GSI Express',
        'etc'      => '기타',
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

    public function __construct(
        private readonly KgInicisApiService $apiService,
    ) {
        parent::__construct();
    }

    /**
     * formData
     *
     * @param  string  $orderNumber
     * @return JsonResponse
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

        // payment_meta에서 이력 추출
        $meta           = $payment->payment_meta ? json_decode($payment->payment_meta, true) : [];
        $escrowDelivery = $meta['escrow_delivery'] ?? null;
        $escrowConfirm  = $meta['escrow_confirm'] ?? null;
        $denyConfirmed  = isset($meta['escrow_deny_confirm']);

        return ResponseHelper::success('messages.success', [
            'has_escrow_payment'   => true,
            'tid'                  => $payment->transaction_id,
            'price'                => (int) round((float) $payment->paid_amount_local),
            'courier_codes'        => self::COURIER_CODES,
            'prefill'              => [
                'recvName'  => $address?->recipient_name ?? '',
                'recvTel'   => $address?->recipient_phone ?? '',
                'recvPost'  => $address?->zipcode ?? '',
                'recvAddr'  => trim(($address?->address ?? '') . ' ' . ($address?->address_detail ?? '')),
            ],
            'registered_delivery'  => $escrowDelivery,
            'escrow_confirm'       => $escrowConfirm,
            'deny_confirmed'       => $denyConfirmed,
        ]);
    }

    /**
     * register
     *
     * @param  Request  $request
     * @param  string  $orderNumber
     * @return JsonResponse
     */
    public function register(Request $request, string $orderNumber): JsonResponse
    {
        $invoice = trim((string) $request->input('invoice', ''));
        $exCode  = trim((string) $request->input('ex_code', ''));

        if ($invoice === '') {
            return ResponseHelper::error('messages.failed', 422, ['invoice' => ['운송장번호를 입력해주세요.']]);
        }

        if ($exCode === '' || ! array_key_exists($exCode, self::COURIER_CODES)) {
            return ResponseHelper::error('messages.failed', 422, ['ex_code' => ['택배사를 선택해주세요.']]);
        }

        $payment = $this->findEscrowPayment($orderNumber);

        if (! $payment) {
            return ResponseHelper::error('messages.failed', 404, null);
        }

        $report    = in_array($request->input('report'), ['I', 'U'], true) ? $request->input('report') : 'I';
        $charge    = in_array($request->input('charge'), ['SH', 'BH'], true) ? $request->input('charge') : 'SH';
        $price     = (int) round((float) $payment->paid_amount_local);
        $exName    = self::COURIER_CODES[$exCode];

        // 수신자 주소: 요청 우선, 없으면 DB 조회
        $recvName = trim((string) $request->input('recv_name', ''));
        $recvTel  = trim((string) $request->input('recv_tel', ''));
        $recvPost = trim((string) $request->input('recv_post', ''));
        $recvAddr = trim((string) $request->input('recv_addr', ''));

        if ($recvName === '' || $recvTel === '' || $recvAddr === '') {
            $address = DB::table('ecommerce_order_addresses as a')
                ->join('ecommerce_orders as o', 'o.id', '=', 'a.order_id')
                ->where('o.order_number', $orderNumber)
                ->where('a.address_type', 'shipping')
                ->select(['a.recipient_name', 'a.recipient_phone', 'a.zipcode', 'a.address', 'a.address_detail'])
                ->first();

            $recvName = $recvName ?: ($address?->recipient_name ?? '');
            $recvTel  = $recvTel  ?: ($address?->recipient_phone ?? '');
            $recvPost = $recvPost ?: ($address?->zipcode ?? '');
            $recvAddr = $recvAddr ?: trim(($address?->address ?? '') . ' ' . ($address?->address_detail ?? ''));
        }

        Log::info('KG Inicis: escrow delivery register requested', [
            'order_number' => $orderNumber,
            'tid'          => $payment->transaction_id,
            'invoice'      => $invoice,
            'ex_code'      => $exCode,
            'report'       => $report,
        ]);

        try {
            // 에스크로 결제는 반드시 에스크로 MID/키 사용
            $this->apiService->useEscrowCredentials(true);

            $pgResponse = $this->apiService->registerEscrowDelivery([
                'tid'         => $payment->transaction_id,
                'oid'         => $orderNumber,
                'price'       => $price,
                'report'      => $report,
                'invoice'     => $invoice,
                'registName'  => trim((string) $request->input('regist_name', '')),
                'exCode'      => $exCode,
                'exName'      => $exName,
                'charge'      => $charge,
                'invoiceDay'  => date('Y-m-d H:i:s'),
                'sendName'    => trim((string) $request->input('send_name', '')),
                'sendTel'     => trim((string) $request->input('send_tel', '')),
                'sendPost'    => trim((string) $request->input('send_post', '')),
                'sendAddr1'   => trim((string) $request->input('send_addr', '')),
                'recvName'    => $recvName,
                'recvTel'     => $recvTel,
                'recvPost'    => $recvPost,
                'recvAddr'    => $recvAddr,
            ]);

            $resultCode = $pgResponse['resultCode'] ?? '';
            $sanitizedPgResponse = $this->sanitizePgResponse($pgResponse, self::ESCROW_DELIVERY_RESPONSE_KEYS);

            if ($resultCode !== '00') {
                Log::warning('KG Inicis: escrow delivery register failed', [
                    'order_number' => $orderNumber,
                    'result_code'  => $resultCode,
                    'result_msg'   => $pgResponse['resultMsg'] ?? '',
                    'pg_response'  => $sanitizedPgResponse,
                ]);

                return ResponseHelper::error('messages.failed', 502, [
                    'message' => [$pgResponse['resultMsg'] ?? '배송등록에 실패했습니다.'],
                ]);
            }

            // payment_meta에 배송등록 정보 저장
            $meta = $payment->payment_meta ? json_decode($payment->payment_meta, true) : [];
            $meta['pg_response_sanitized'] = true;
            $meta['escrow_delivery'] = [
                'registered_at' => now()->toDateTimeString(),
                'report'        => $report,
                'invoice'       => $invoice,
                'ex_code'       => $exCode,
                'ex_name'       => $exName,
                'charge'        => $charge,
                'recv_name'     => $recvName,
                'recv_addr'     => $recvAddr,
                'pg_response'   => $sanitizedPgResponse,
            ];

            DB::table('ecommerce_order_payments')
                ->where('id', $payment->id)
                ->update([
                    'payment_meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                    'updated_at'   => now(),
                ]);

            Log::info('KG Inicis: escrow delivery registered', [
                'order_number' => $orderNumber,
                'tid'          => $payment->transaction_id,
                'invoice'      => $invoice,
                'ex_name'      => $exName,
            ]);

            return ResponseHelper::success('messages.success', [
                'result_code' => $resultCode,
                'result_msg'  => $pgResponse['resultMsg'] ?? 'OK',
                'invoice'     => $invoice,
                'ex_name'     => $exName,
            ]);

        } catch (\Exception $e) {
            Log::error('KG Inicis: escrow delivery register exception', [
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
            ->where('p.pg_provider', 'kginicis')
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
