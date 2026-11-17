<?php

// audit:allow api-doc-coverage reason: 응답을 공통 형식(ResponseHelper)으로 바꾸고 오류 문구를 error 대신 번역된 message 에 싣는다 — 결제 핸들러는 error 와 message 를 모두 읽는다(#916). 해당 엔드포인트 문서 부재는 사전 상태

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Helpers\ResponseHelper;
use App\Support\SiteUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\OrderRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Plugins\Sirsoft\PayNhnkcp\Concerns\BindsMobileVbankSession;
use Plugins\Sirsoft\PayNhnkcp\Concerns\HandlesClaimPaymentCallbacks;
use Plugins\Sirsoft\PayNhnkcp\Concerns\RecordsPaymentWindowClosure;
use Plugins\Sirsoft\PayNhnkcp\Concerns\VerifiesOrderOwnership;
use Plugins\Sirsoft\PayNhnkcp\Exceptions\NhnKcpApiException;
use Plugins\Sirsoft\PayNhnkcp\Services\KcpSoapService;

/**
 * KCP SmartPhone Pay 승인키 컨트롤러
 *
 * PC Standard Pay(iframe)와 달리 모바일은 서버에서 SOAP으로 approval_key 를
 * 먼저 획득하고, 브라우저가 pay_url 로 form POST(페이지 전환)한다.
 */
class MobileApprovalController
{
    use BindsMobileVbankSession;
    use HandlesClaimPaymentCallbacks;
    use RecordsPaymentWindowClosure;
    use VerifiesOrderOwnership;

    /** 결제수단 → KCP 모바일 pay_method 코드 */
    private const MOBILE_PAY_METHOD_MAP = [
        'card' => 'CARD',
        'bank_transfer' => 'BANK',
        'virtual_account' => 'VCNT',
        'mobile' => 'MOBX',
        'bank' => 'BANK',
        'vbank' => 'VCNT',
        'phone' => 'MOBX',
    ];

    /** KCP 모바일 pay_method → ActionResult (form hidden field) */
    private const ACTION_RESULT_MAP = [
        'CARD' => 'card',
        'BANK' => 'acnt',
        'MOBX' => 'mobx',
        'VCNT' => 'vcnt',
    ];

    /** 간편결제 direct 파라미터 기본값 (매 결제 시 초기화) */
    private const EASY_PAY_DIRECT_DEFAULTS = [
        'payco_direct' => '',
        'naverpay_direct' => 'A',
        'kakaopay_direct' => 'A',
        'applepay_direct' => 'A',
    ];

    /** 간편결제별 direct 파라미터 override (기본값 위에 덮어씀) */
    private const EASY_PAY_DIRECT_FIELDS = [
        'nhnkcp_payco' => ['payco_direct' => 'Y'],
        'nhnkcp_naverpay' => ['naverpay_direct' => 'Y'],
        'nhnkcp_naverpay_point' => ['naverpay_direct' => 'Y', 'naverpay_point_direct' => 'Y'],
        'nhnkcp_kakaopay' => ['kakaopay_direct' => 'Y'],
        'nhnkcp_applepay' => ['applepay_direct' => 'Y'],
    ];

    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    /** 결제 결과 수신 경로 (모바일 Ret_URL) */
    private const CALLBACK_PATH = '/plugins/sirsoft-pay_nhnkcp/payment/callback';

    /**
     * @param  KcpSoapService  $soapService  KCP SOAP 서비스
     * @param  PaymentGatewayBridge  $bridge  이커머스 결제 계약 (주문·클레임 결제 조회, 소유자 확인, 재결제 복구, 결제 meta 기록)
     */
    public function __construct(
        private readonly KcpSoapService $soapService,
        private readonly PaymentGatewayBridge $bridge,
    ) {}

    /**
     * KCP 모바일 결제 승인키 획득
     *
     * POST /api/plugins/sirsoft-pay_nhnkcp/mobile/approval-key
     *
     * @return JsonResponse{ success: true, data: { pay_url, fields } }
     */
    /**
     * 모바일 결제 승인키 발급 (SOAP)
     *
     * KCP 모바일 결제창 진입 전 SOAP 으로 승인키 + payUrl 발급.
     *
     * @param  Request  $request  주문/결제 메타
     * @return JsonResponse approval_key + pay_url
     */
    // audit:allow controller-base-request-injection reason: 인라인 validate 로 전 필드 계약 보유 — FormRequest 승격은 후속 백로그 (계획 범위 제외 합의)
    public function getApprovalKey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_number' => ['required', 'string', 'max:40'],
            'amount' => ['required', 'integer', 'min:100'],
            'good_name' => ['required', 'string', 'max:100'],
            'pay_method' => ['required', 'string'],
            'buyr_name' => ['nullable', 'string', 'max:50'],
            'buyr_mail' => ['nullable', 'string', 'email', 'max:100'],
            'buyr_tel1' => ['nullable', 'string', 'max:20'],
            // 구버전 프런트 호환으로만 받는다 — 값은 쓰지 않는다(아래 Ret_URL 은 서버가 사이트 주소로 만든다).
            'ret_url' => ['nullable', 'string'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        // 보안: KCP 에 등록하는 결과 수신 주소는 클라이언트가 보낸 값이 아니라 서버가 사이트 주소로 만든다 —
        // 임의 절대 URL 을 받으면 결제 결과·가상계좌 정보가 그 도메인으로 간다.
        $retUrl = SiteUrl::absolute(self::CALLBACK_PATH);

        $payMethodKey = strtolower($validated['pay_method']);
        $isEasyPay = str_starts_with($payMethodKey, 'nhnkcp_');
        $mobilePayMethod = $isEasyPay
            ? 'CARD'
            : (self::MOBILE_PAY_METHOD_MAP[$payMethodKey] ?? 'CARD');

        $settings = plugin_settings(self::PLUGIN_IDENTIFIER) ?? [];
        $useEscrow = (bool) ($settings['use_escrow'] ?? false) && ! $isEasyPay;

        try {
            $order = null;

            // 클레임 추가결제(CP 채번)는 주문이 아니다 — 결제 건 기준으로 같은 강도의 검증을 적용한다.
            if ($this->bridge->isClaimPaymentNumber($validated['order_number'])) {
                // 입금 대기 수단(가상계좌)은 클레임 결제 상태 모델에 대기 상태가 없어 지원하지 않는다.
                if ($mobilePayMethod === 'VCNT') {
                    return ResponseHelper::pluginError('sirsoft-pay_nhnkcp', 'messages.responses.claim_deposit_not_supported', 422);
                }

                $claimError = $this->validateClaimApprovalKeyContext(
                    $request,
                    (string) $validated['order_number'],
                    (int) $validated['amount'],
                    $validated['currency'] ?? null,
                );

                if ($claimError !== null) {
                    return $claimError;
                }
            } else {
                $order = $this->bridge->findOrderByNumber($validated['order_number'], 'nhnkcp');
                if (! $order) {
                    return ResponseHelper::pluginError('sirsoft-pay_nhnkcp', 'messages.responses.order_not_found', 404);
                }

                // 회원 주문은 로그인 본인(아니면 403), 비회원 주문은 그 주문의 조회 토큰(아니면 401) — 클레임 경로와 한 판정(#883, #855)
                $ownershipError = $this->orderOwnershipRejection(
                    $request,
                    $order,
                    'messages.responses.order_approval_forbidden',
                );
                if ($ownershipError !== null) {
                    return $ownershipError;
                }

                $expectedAmount = $this->resolveExpectedPaymentPriceOrNull($order, 'mobile_approval_key', [
                    'order_number' => $validated['order_number'],
                    'requested_amount' => $validated['amount'],
                    'requested_currency' => $validated['currency'] ?? null,
                    'ip' => $request->ip(),
                ]);
                if ($expectedAmount === null) {
                    return ResponseHelper::pluginError('sirsoft-pay_nhnkcp', 'messages.responses.currency_not_chargeable', 422);
                }

                if ((int) $validated['amount'] !== $expectedAmount) {
                    return ResponseHelper::pluginError('sirsoft-pay_nhnkcp', 'messages.responses.amount_mismatch', 422);
                }

                if (! $this->isKrwPayment($order, $validated['currency'] ?? null)) {
                    return ResponseHelper::pluginError('sirsoft-pay_nhnkcp', 'messages.responses.krw_only', 422);
                }

                if (! $order->orderStatus->isBeforePayment()) {
                    $restored = $this->restoreKcpOrderForRetry($order, (int) $validated['amount']);
                    if ($restored === null) {
                        return ResponseHelper::pluginError('sirsoft-pay_nhnkcp', 'messages.responses.order_not_retryable', 409);
                    }

                    $order = $restored;
                }
            }

            $result = $this->soapService->getApprovalKey(
                orderNumber: $validated['order_number'],
                goodName: $validated['good_name'],
                amount: (int) $validated['amount'],
                payMethod: $mobilePayMethod,
                retUrl: $retUrl,
                payMethodKey: $payMethodKey,
                escrow: $useEscrow,
            );

            $siteCd = $useEscrow
                ? $this->soapService->getEscrowSiteCd()
                : $this->soapService->getSiteCd($payMethodKey);

            $fields = [
                'req_tx' => 'pay',
                'site_cd' => $siteCd,
                'ordr_idxx' => $validated['order_number'],
                'pay_method' => $mobilePayMethod,
                'good_mny' => (string) $validated['amount'],
                'good_name' => $validated['good_name'],
                'buyr_name' => $validated['buyr_name'] ?? '',
                'buyr_mail' => $validated['buyr_mail'] ?? '',
                'buyr_tel1' => $validated['buyr_tel1'] ?? '',
                'Ret_URL' => $retUrl,
                'ActionResult' => self::ACTION_RESULT_MAP[$mobilePayMethod] ?? 'card',
                'escw_used' => $useEscrow ? 'Y' : 'N',
                'quotaopt' => '12',
                'currency' => '410',
                'approval_key' => $result['approval_key'],
                ...($order !== null ? $this->buildTaxFields($order, (int) $validated['amount']) : []),
            ];

            // 가상계좌 전용 파라미터
            if ($mobilePayMethod === 'VCNT') {
                $settings = plugin_settings(self::PLUGIN_IDENTIFIER) ?? [];
                $fields = array_merge($fields, $this->buildVbankExpireFields($order, $settings));
                $fields['disp_tax_yn'] = 'N';

                // 모바일 가상계좌 콜백은 계좌번호를 브라우저 평문으로만 전달한다.
                // 이 지점은 주문 소유자 확인(회원 Sanctum / 비회원 조회 토큰)을 통과했으므로 여기서 만든 일회성
                // nonce 를 passthrough 파라미터로 실어 보내고, 콜백에서 되돌아온 값과
                // 대조해 그 콜백이 이 결제 세션에서 비롯됐음을 확인한다.
                // param_opt_1 은 간편결제 수단 식별자가 점유하므로 param_opt_2 를 쓴다.
                $mobileVbankState = $this->issueMobileVbankState($order);
                if ($mobileVbankState === null) {
                    return ResponseHelper::pluginError('sirsoft-pay_nhnkcp', 'messages.responses.vbank_session_failed', 500);
                }

                $fields['param_opt_2'] = $mobileVbankState;
            }

            if ($isEasyPay) {
                // GNU5 규격: 모든 direct 파라미터를 기본값으로 초기화 후 선택된 수단만 Y로 덮어씀
                $fields = [...$fields, ...self::EASY_PAY_DIRECT_DEFAULTS];
                if (isset(self::EASY_PAY_DIRECT_FIELDS[$payMethodKey])) {
                    $fields = [...$fields, ...self::EASY_PAY_DIRECT_FIELDS[$payMethodKey]];
                }
                $fields = [...$fields, ...$this->buildEasyPayReturnFields($payMethodKey)];
            }

            return ResponseHelper::success('common.success', [
                'pay_url' => $result['pay_url'],
                'fields' => $fields,
            ]);

        } catch (NhnKcpApiException $e) {
            // 예외 문구는 KCP 서비스가 번역 키로 만든 안내(연결 불가·승인키 거절 사유)라 사유 자리에 그대로 싣는다.
            return ResponseHelper::pluginError('sirsoft-pay_nhnkcp', 'messages.responses.approval_key_failed', 422, null, [
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 가상계좌 계좌 만료 기간 필드를 만듭니다. (주문 입금 기한 = 쇼핑몰 자동취소 기한)
     *
     * KCP 는 만료를 「발급일로부터 N일(`vcnt_expire_term`) · 그날 HHMMSS(`vcnt_expire_term_time`)까지」로 받는다.
     * 일수는 한국 날짜로 센다. 기한이 없거나 오늘 이전이면 종전 예비값(플러그인 `vbank_expire_days`, 없으면 3일)으로 연다
     * — PC 결제창(`buildKcpVbankExpireFields`)과 같은 규칙이다.
     *
     * @param  OrderRecord|null  $order  대상 주문
     * @param  array<string, mixed>  $settings  플러그인 설정
     * @return array<string, string> KCP 가상계좌 만료 필드
     */
    private function buildVbankExpireFields(?OrderRecord $order, array $settings): array
    {
        // KCP 가상계좌 입금기한(vcnt_expire_term)은 KCP 규격상 한국 시간 일수·시각이다
        $due = $order?->payment?->vbankDueAt?->setTimezone('Asia/Seoul');

        if ($due !== null) {
            $days = (int) Carbon::now()->setTimezone('Asia/Seoul')->startOfDay()->diffInDays($due->copy()->startOfDay(), false);

            if ($days >= 1) {
                // audit:allow datetime-display-user-timezone reason: PG 규격값(KCP vcnt_expire_term) — Asia/Seoul 고정
                return ['vcnt_expire_term' => (string) $days, 'vcnt_expire_term_time' => $due->format('His')];
            }
        }

        return ['vcnt_expire_term' => (string) ((int) ($settings['vbank_expire_days'] ?? 3))];
    }

    /**
     * 주문의 과세/비과세 분할 필드 반환 (복합과세 사이트 코드 계약 시 필요)
     *
     * 비과세 금액이 있는 경우에만 comm_tax_mny / comm_vat_mny / comm_free_mny 추가.
     * 전액 과세라면 good_mny 만으로 KCP가 내부적으로 세금 계산.
     *
     * @param  OrderRecord  $order  과세·면세 금액을 읽을 주문
     * @param  int  $paymentAmount  결제 금액 (KRW)
     * @return array<string, string>
     */
    private function buildTaxFields(OrderRecord $order, int $paymentAmount): array
    {
        // 면세 = 면세 + 영세, 과세 합계 = 영세를 뺀 과세 합계(이커머스 1.3.3, #612) — 영세는 부가세가 없어 KCP 에는 비과세로 넘긴다.
        // 세 값은 이커머스 결제 계약(pgTaxSplit)이 주문 저장값으로 계산한다.
        $taxSplit = $this->bridge->pgTaxSplit($order->orderNumber);
        $taxFreeAmt = (int) round($taxSplit->taxFree);
        if ($taxFreeAmt <= 0) {
            return [];
        }

        $paymentAmount = max(0, $paymentAmount);
        $taxFreeAmt = min($taxFreeAmt, $paymentAmount);
        $taxablePaymentAmt = max(0, $paymentAmount - $taxFreeAmt);
        $taxTotalAmt = (int) round($taxSplit->taxable);
        $vatAmt = (int) round($taxSplit->vat);
        if ($taxablePaymentAmt > 0 && $taxTotalAmt > 0 && $vatAmt > 0) {
            $vatAmt = min($taxablePaymentAmt, (int) round($taxablePaymentAmt * ($vatAmt / $taxTotalAmt)));
        } else {
            $vatAmt = 0;
        }
        $supplyAmt = $taxablePaymentAmt - $vatAmt; // 공급가액 (VAT 제외)

        return [
            'tax_flag' => 'TG03',
            'comm_tax_mny' => (string) $supplyAmt,
            'comm_vat_mny' => (string) $vatAmt,
            'comm_free_mny' => (string) $taxFreeAmt,
        ];
    }

    /**
     * 간편결제 수단이면 KCP 모바일 결제창의 간편결제 지정 필드를 만듭니다.
     *
     * @param  string  $payMethodKey  쇼핑몰 결제수단 키
     * @return array<string, string> KCP 간편결제 필드 (간편결제가 아니면 빈 배열)
     */
    private function buildEasyPayReturnFields(string $payMethodKey): array
    {
        if (! isset(self::EASY_PAY_DIRECT_FIELDS[$payMethodKey])) {
            return [];
        }

        return [
            'param_opt_1' => $payMethodKey,
            'nhnkcp_easy_pay_method' => $payMethodKey,
        ];
    }

    /**
     * 요청 통화와 주문 통화가 모두 KRW 인지 판정합니다. (KCP 는 KRW 전용)
     *
     * @param  OrderRecord  $order  대상 주문
     * @param  string|null  $requestedCurrency  요청이 보낸 결제 통화
     * @return bool 둘 다 KRW 이면 true
     */
    private function isKrwPayment(OrderRecord $order, ?string $requestedCurrency): bool
    {
        return $this->normalizeCurrency($requestedCurrency) === 'KRW'
            && $this->normalizeCurrency($order->currency) === 'KRW';
    }

    /**
     * 통화 코드를 대문자로 정규화합니다. (빈 값이면 KRW)
     *
     * @param  string|null  $currency  통화 코드
     * @return string 정규화된 통화 코드
     */
    private function normalizeCurrency(?string $currency): string
    {
        $normalized = strtoupper(trim((string) $currency));

        return $normalized !== '' ? $normalized : 'KRW';
    }
}
