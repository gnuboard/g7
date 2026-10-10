<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 추가결제 PG 원응답 마스킹 테스트 (P18)
 *
 * PG 원응답에는 휴대전화·이메일·시크릿 키 같은 값이 섞여 온다. 형제 서비스
 * (`CashReceiptService`)는 저장 전에 마스킹을 거치는데 클레임 결제만 원문을 그대로
 * `raw_response` 에 적재하고 있었다 — 같은 성격의 값이 한쪽에서만 보호되면 그 비대칭이
 * 곧 유출 경로다. 두 서비스가 같은 trait 을 쓰므로 판정 기준도 하나다.
 */
class ClaimPaymentRawResponseMaskingTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected ClaimPaymentService $service;

    protected OrderReturnService $returnService;

    /**
     * 테스트 전제(통화·사유·서비스 등)를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->service = app(ClaimPaymentService::class);
        $this->returnService = app(OrderReturnService::class);
    }

    /**
     * 테스트가 바꾼 설정·훅을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        HookManager::clearFilter('sirsoft-ecommerce.claim_payment.approve');

        parent::tearDown();
    }

    /**
     * 배송비가 청구되는 반품을 접수합니다.
     *
     * @return OrderReturn 접수된 반품 신청서
     */
    private function requestedReturn(): OrderReturn
    {
        $order = $this->deliveredClaimOrder();

        return $this->returnService->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'requested_by' => $order->user_id,
        ]);
    }

    /**
     * 민감 키를 담은 원응답을 돌려주는 승인 훅을 세웁니다.
     *
     * @param  bool  $success  승인 성공 여부
     * @return void 반환값 없음
     */
    private function stubApproveHook(bool $success): void
    {
        HookManager::addFilter('sirsoft-ecommerce.claim_payment.approve', fn (array $default, $payment) => array_merge($default, [
            'handled' => true,
            'success' => $success,
            'transaction_id' => $success ? 'TXN-MASK' : null,
            // 승인 금액을 돌려주지 않으면 서버가 대조 불가로 거절한다(#599 validate P2 #11).
            'paid_amount' => $success ? $this->service->resolvePaymentCharge($payment)['minor_unit_amount'] : null,
            'error_code' => $success ? null : 'PG_DECLINED',
            'error_message' => $success ? null : 'declined',
            'raw_response' => [
                'resultCode' => '0000',
                'customerMobilePhone' => '01012345678',
                'buyer' => ['customerEmail' => 'buyer@example.com'],
                'secretKey' => 'sk_live_zzz',
            ],
        ]));
    }

    /**
     * 저장된 원응답에서 민감 키가 마스킹되고 대사용 값은 남는지 단언합니다.
     *
     * @param  array<mixed, mixed>|null  $raw  저장된 원응답
     * @return void 반환값 없음
     */
    private function assertMasked(?array $raw): void
    {
        $this->assertIsArray($raw, '원응답이 저장되지 않았다면 이 테스트는 아무것도 재지 못한다');
        $this->assertSame('***', $raw['customerMobilePhone'] ?? null, '휴대전화 원문이 저장됐다');
        $this->assertSame('***', $raw['buyer']['customerEmail'] ?? null, '중첩 배열의 이메일 원문이 저장됐다');
        $this->assertSame('***', $raw['secretKey'] ?? null, '시크릿 키 원문이 저장됐다');
        $this->assertSame('0000', $raw['resultCode'] ?? null, '대사에 필요한 값까지 지우면 안 된다');
    }

    /**
     * 승인 성공 시 저장되는 원응답이 마스킹된다.
     *
     * @scenario defect=raw_response_masking
     *
     * @effects approved_claim_payment_raw_response_is_masked
     *
     * @return void 반환값 없음
     */
    public function test_approved_payment_stores_a_masked_raw_response(): void
    {
        $this->registerTestPgProvider();
        $payment = $this->service->initiateForRequest($this->requestedReturn(), PaymentMethodEnum::CARD->value);

        $this->stubApproveHook(true);

        $approved = $this->service->approvePgPayment($payment, []);

        $this->assertMasked($approved->fresh()->raw_response);
    }

    /**
     * 승인 실패 시 저장되는 원응답도 마스킹된다.
     *
     * 실패 경로는 운영자가 사유를 보려고 가장 자주 여는 자리라 오히려 노출 폭이 넓다.
     *
     * @scenario defect=raw_response_masking
     *
     * @effects failed_claim_payment_raw_response_is_masked
     *
     * @return void 반환값 없음
     */
    public function test_failed_payment_stores_a_masked_raw_response(): void
    {
        $this->registerTestPgProvider();
        $payment = $this->service->initiateForRequest($this->requestedReturn(), PaymentMethodEnum::CARD->value);

        $this->stubApproveHook(false);

        try {
            $this->service->approvePgPayment($payment, []);
            $this->fail('결제 실패 예외가 발생해야 합니다.');
        } catch (OrderClaimException) {
            // 실패는 예외로 끝난다 — 이 테스트가 보는 것은 그 직전에 적재된 원응답이다.
        }

        $this->assertMasked(ClaimPayment::find($payment->id)->raw_response);
    }
}
