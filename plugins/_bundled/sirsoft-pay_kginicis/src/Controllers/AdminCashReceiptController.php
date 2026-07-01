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
 * KG 이니시스 현금영수증 별도발행 관리자 컨트롤러
 *
 * 메뉴얼: https://manual.inicis.com/pay/etc-receipt.html
 */
class AdminCashReceiptController extends AdminBaseController
{
    use SanitizesPgResponse;

    /** 현금영수증 발행 PG 응답 저장/반환 허용 필드 */
    private const CASH_RECEIPT_RESPONSE_KEYS = [
        'resultCode',
        'resultMsg',
        'tid',
        'TID',
        'cashReceiptNo',
        'cashReceiptTid',
        'applNum',
        'applDate',
        'applTime',
        'mid',
        'MID',
    ];

    public function __construct(
        private readonly KgInicisApiService $apiService,
    ) {
        parent::__construct();
    }

    /**
     * issue
     *
     * @param  Request  $request
     * @param  string  $orderNumber
     * @return JsonResponse
     */
    public function issue(Request $request, string $orderNumber): JsonResponse
    {
        $issueType = (string) $request->input('issue_type', '0');
        $issueNumber = trim((string) $request->input('issue_number', ''));

        if (! in_array($issueType, ['0', '1'], true)) {
            return ResponseHelper::error('messages.failed', 422, ['issue_type' => [__('sirsoft-pay_kginicis::messages.errors.cash_receipt_invalid_issue_type')]]);
        }

        if ($issueNumber === '') {
            return ResponseHelper::error('messages.failed', 422, ['issue_number' => [__('sirsoft-pay_kginicis::messages.errors.cash_receipt_missing_issue_number')]]);
        }

        $payment = DB::table('ecommerce_order_payments as p')
            ->join('ecommerce_orders as o', 'o.id', '=', 'p.order_id')
            ->leftJoin('ecommerce_order_addresses as oa', function ($join) {
                $join->on('oa.order_id', '=', 'o.id')
                    ->where('oa.address_type', '=', 'shipping');
            })
            ->where('o.order_number', $orderNumber)
            ->where('p.pg_provider', 'kginicis')
            ->whereNotNull('p.transaction_id')
            ->select([
                'p.id',
                'p.transaction_id',
                'p.paid_amount_local',
                'p.vat_amount',
                'p.buyer_name',
                'p.buyer_email',
                'p.buyer_phone',
                'p.payment_name',
                'p.payment_meta',
                'p.is_cash_receipt_issued',
                'oa.orderer_name as address_orderer_name',
                'oa.orderer_email as address_orderer_email',
                'oa.orderer_phone as address_orderer_phone',
            ])
            ->first();

        if (! $payment) {
            return ResponseHelper::error('messages.failed', 404, null);
        }

        if ($payment->is_cash_receipt_issued) {
            return ResponseHelper::error('messages.failed', 409, ['message' => [__('sirsoft-pay_kginicis::messages.errors.cash_receipt_already_issued')]]);
        }

        // 구매자 정보는 결제 테이블 → 주문 배송지 순서로 사용한다. PG raw 응답은
        // 민감정보 최소화를 위해 구매자 연락처 fallback 에 사용하지 않는다.
        $meta = $payment->payment_meta ? json_decode($payment->payment_meta, true) : [];
        $rawResponse = $meta['pg_raw_response'] ?? [];

        $buyerName  = $payment->buyer_name  ?? $payment->address_orderer_name ?? '';
        $buyerEmail = $payment->buyer_email ?? $payment->address_orderer_email ?? '';
        $buyerTel   = $payment->buyer_phone ?? $payment->address_orderer_phone ?? '';
        $goodName   = $payment->payment_name ?? $rawResponse['goodName'] ?? $rawResponse['goodsName'] ?? __('sirsoft-pay_kginicis::messages.defaults.good_name');

        $price = (int) round((float) $payment->paid_amount_local);

        // 부가세: DB에 저장된 값 우선, 없으면 총액의 10/110 으로 계산
        $vatAmount = (int) round((float) $payment->vat_amount);
        if ($vatAmount <= 0) {
            $vatAmount = (int) round($price / 11);
        }
        $supplyPrice = $price - $vatAmount;

        Log::info('KG Inicis: cash receipt issue requested', [
            'order_number' => $orderNumber,
            'tid'          => $payment->transaction_id,
            'issue_type'   => $issueType,
            'price'        => $price,
        ]);

        try {
            $pgResponse = $this->apiService->issueCashReceipt([
                'issueType'   => $issueType,
                'issueNumber' => $issueNumber,
                'price'       => $price,
                'supplyPrice' => $supplyPrice,
                'tax'         => $vatAmount,
                'goodName'    => $goodName,
                'buyerName'   => $buyerName,
                'buyerEmail'  => $buyerEmail,
                'buyerTel'    => $buyerTel,
            ]);

            $resultCode = $pgResponse['resultCode'] ?? '';

            if ($resultCode !== '00') {
                Log::warning('KG Inicis: cash receipt issue failed', [
                    'order_number' => $orderNumber,
                    'result_code'  => $resultCode,
                    'result_msg'   => $pgResponse['resultMsg'] ?? '',
                ]);

                return ResponseHelper::error('messages.failed', 502, [
                    'message' => [$pgResponse['resultMsg'] ?? __('sirsoft-pay_kginicis::messages.errors.cash_receipt_issue_failed')],
                ]);
            }

            $sanitizedPgResponse = $this->sanitizePgResponse($pgResponse, self::CASH_RECEIPT_RESPONSE_KEYS);

            // DB 업데이트
            DB::table('ecommerce_order_payments')
                ->where('id', $payment->id)
                ->update([
                    'is_cash_receipt_issued'   => true,
                    'cash_receipt_type'        => $issueType === '0' ? 'income_deduction' : 'expenditure_proof',
                    'cash_receipt_identifier'  => $this->maskCashReceiptIdentifier($issueNumber),
                    'cash_receipt_issued_at'   => now(),
                    'updated_at'               => now(),
                ]);

            Log::info('KG Inicis: cash receipt issued', [
                'order_number' => $orderNumber,
                'tid'          => $payment->transaction_id,
                'issue_type'   => $issueType,
            ]);

            return ResponseHelper::success('messages.success', [
                'result_code' => $resultCode,
                'result_msg'  => $pgResponse['resultMsg'] ?? 'OK',
                'pg_response' => $sanitizedPgResponse,
            ]);

        } catch (\Exception $e) {
            Log::error('KG Inicis: cash receipt issue exception', [
                'order_number' => $orderNumber,
                'error'        => $e->getMessage(),
            ]);

            return ResponseHelper::error('messages.failed', 500, [
                'message' => [$e->getMessage()],
            ]);
        }
    }

    /**
     * 현금영수증 발행 식별번호를 저장용 마스킹 값으로 변환합니다.
     *
     * @param  string  $identifier  PG 발행 요청에 사용한 원본 식별번호
     * @return string 끝 4자리만 남긴 마스킹 값
     */
    private function maskCashReceiptIdentifier(string $identifier): string
    {
        $digits = preg_replace('/\D+/', '', $identifier) ?? '';
        if ($digits === '') {
            return str_repeat('*', max(1, mb_strlen($identifier)));
        }

        $length = strlen($digits);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4) . substr($digits, -4);
    }
}
