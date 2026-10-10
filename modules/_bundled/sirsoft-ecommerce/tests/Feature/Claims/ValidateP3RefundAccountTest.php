<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use App\Models\User;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 환불 계좌·환불 수단 게이트 결함 수정 검증 (#599 validate P3 — 06 #10·#11·#12·minor)
 *
 * 검증 축:
 * - [환불하기] 수단 게이트가 [금액 수정]과 같은 강도다 — 카드 주문의 계좌 수동 환불을 허용한다(#11, 2026-09-23 결정).
 * - 계좌 수동 환불은 보낼 계좌가 있어야 실행된다(실행 수단 축).
 * - 계좌로 돈이 돌아가는 주문은 반품 신청에서도 계좌를 필수로 받는다(2026-09-23 결정).
 * - `claimable-options` 는 등록 계좌를 가린 모양으로 내고, 손대지 않은 제출은 원문으로 되돌린다(#12).
 * - 계좌 원문 열람을 클레임 권한(`claims.*`)에도 인정한다(#12).
 * - 신청 수정 요청이 접수와 같은 강도로 사유를 검증한다(minor).
 */
class ValidateP3RefundAccountTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected User $user;

    /**
     * 통화·사유를 심고 회원을 만든 뒤, PG 환불 훅과 첨부 저장소를 성공으로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->user = $this->createUser();

        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => true, 'transaction_id' => 'PGTXN-P3', 'error_code' => null, 'error_message' => null,
        ]);

        $storage = Mockery::mock(StorageInterface::class);
        $storage->allows('put')->andReturn(true)->byDefault();
        $storage->allows('delete')->andReturn(true)->byDefault();
        $storage->allows('exists')->andReturn(true)->byDefault();
        $storage->allows('getDisk')->andReturn('local')->byDefault();
        $this->app->when(ClaimAttachmentService::class)->needs(StorageInterface::class)->give(fn () => $storage);
    }

    /**
     * 통화 설정과 목을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 승인된 직접 환불신청서를 만듭니다. (결제수단·저장 수단 지정)
     *
     * @param  string  $paymentMethod  결제수단 코드
     * @param  RefundMethodEnum  $storedMethod  신청서에 저장된 환불 수단
     * @return OrderRefund 승인된 환불신청서
     */
    protected function approvedRefund(string $paymentMethod, RefundMethodEnum $storedMethod): OrderRefund
    {
        $order = $this->deliveredClaimOrder();
        $order = $this->moveOrderTo($order, OrderStatusEnum::PREPARING);
        $order->payment?->forceFill([
            'payment_method' => $paymentMethod,
            'pg_provider' => $paymentMethod === 'card' ? 'testpg' : '',
        ])->save();
        $order = $order->fresh(['options', 'payment', 'shippings']);

        $refund = $this->makeDirectRefund($order, $order->options->first(), 1, RefundStatusEnum::APPROVED);
        $refund->update([
            'refund_amount' => 10000,
            'refund_points_amount' => 0,
            'refund_method' => $storedMethod,
            'reason_code' => 'changed_mind',
            'refund_bank_code' => '',
            'refund_bank_account' => '',
            'refund_bank_holder' => '',
        ]);

        return $refund->fresh();
    }

    /**
     * 환불 실행 권한 운영자를 만듭니다.
     *
     * @return User 운영자
     */
    protected function refundExecutor(): User
    {
        return $this->createAdminUser([
            'sirsoft-ecommerce.claims.read',
            'sirsoft-ecommerce.claims.update-status',
            'sirsoft-ecommerce.claims.execute-refund',
        ]);
    }

    /**
     * 이 회원의 배송완료 무통장 주문을 만듭니다. (등록 계좌 지정 가능)
     *
     * @param  array  $registeredBank  주문 시점 등록 계좌 (결제행 컬럼)
     * @return Order 배송완료 주문
     */
    protected function myBankTransferOrder(array $registeredBank = []): Order
    {
        $order = $this->deliveredClaimOrder(paymentOverrides: ['payment_method' => PaymentMethodEnum::DBANK->value] + $registeredBank);
        $order->forceFill(['user_id' => $this->user->id])->save();

        return $order->fresh(['options', 'shippingAddress', 'payment']);
    }

    /**
     * 카드 주문의 계좌 수동 환불을 [환불하기]에서도 받는지 검증합니다.
     *
     * 수정 전: 실행 게이트가 실효 수단(PG)과 다른 값을 `payment_mismatch` 로 막아, [금액 수정]으로 저장한
     * 계좌 수동 환불을 실행할 길이 없었다.
     *
     * @scenario defect=card_order_manual_bank_refund_is_executable
     *
     * @effects card_order_manual_bank_refund_is_executable
     *
     * @return void 반환값 없음
     */
    public function test_card_order_manual_bank_refund_is_executable(): void
    {
        $refund = $this->approvedRefund('card', RefundMethodEnum::PG);

        $this->actingAs($this->refundExecutor())
            ->postJson("/api/modules/sirsoft-ecommerce/admin/refunds/{$refund->id}/execute", [
                'refund_method' => RefundMethodEnum::BANK->value,
                'refund_bank' => ['bank_code' => '004', 'account_number' => '11122233344', 'holder' => '홍길동'],
            ])
            ->assertOk();

        $this->assertSame(RefundMethodEnum::BANK, $refund->fresh()->refund_method);
    }

    /**
     * 카드 주문이라도 계좌 수동 환불을 고르면 보낼 계좌가 있어야 실행되는지 검증합니다.
     *
     * 수정 전: 계좌 요건이 주문 축(무통장·가상계좌)만 봐 카드 주문의 `BANK` 실행에 계좌를 묻지 않는다
     * (수단 게이트가 먼저 막아 이 경로가 열려 있지 않았다).
     *
     * @scenario defect=manual_bank_refund_requires_an_account
     *
     * @effects manual_bank_refund_requires_an_account
     *
     * @return void 반환값 없음
     */
    public function test_card_order_manual_bank_refund_requires_an_account(): void
    {
        $refund = $this->approvedRefund('card', RefundMethodEnum::PG);

        $this->actingAs($this->refundExecutor())
            ->postJson("/api/modules/sirsoft-ecommerce/admin/refunds/{$refund->id}/execute", [
                'refund_method' => RefundMethodEnum::BANK->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('refund_bank');

        $this->assertSame(RefundStatusEnum::APPROVED, $refund->fresh()->refund_status);
    }

    /**
     * 무통장 주문에 PG 환불을 실으면 여전히 막는지 검증합니다. (대조군 — 돈이 나가지 않는 경로)
     *
     * @scenario defect=pg_refund_on_non_pg_order_is_rejected
     *
     * @effects pg_refund_on_non_pg_order_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_pg_refund_on_a_bank_transfer_order_is_rejected(): void
    {
        $refund = $this->approvedRefund('dbank', RefundMethodEnum::BANK);

        $this->actingAs($this->refundExecutor())
            ->postJson("/api/modules/sirsoft-ecommerce/admin/refunds/{$refund->id}/execute", [
                'refund_method' => RefundMethodEnum::PG->value,
                'refund_bank' => ['bank_code' => '004', 'account_number' => '11122233344', 'holder' => '홍길동'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('refund_method');
    }

    /**
     * 계좌로 돈이 돌아가는 주문의 반품 신청이 계좌 없이 접수되지 않는지 검증합니다.
     *
     * 수정 전: 계좌 필수 판정이 취소·환불 요청에만 있어 반품은 201 로 접수된다.
     *
     * @scenario defect=return_request_requires_refund_account_for_bank_refund_orders
     *
     * @effects return_request_requires_refund_account_for_bank_refund_orders
     *
     * @return void 반환값 없음
     */
    public function test_return_request_requires_an_account_when_money_returns_to_a_bank(): void
    {
        $order = $this->myBankTransferOrder();

        $this->actingAs($this->user)
            ->postJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/returns", [
                'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
                'reason_code' => 'changed_mind',
                'charge_method' => 'deduct_from_refund',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('refund_bank');

        $this->assertSame(0, OrderReturn::count());
    }

    /**
     * 같은 가격 교환은 돌려줄 돈이 없어 계좌 없이도 접수되는지 검증합니다. (대조군)
     *
     * @scenario defect=exchange_requires_account_only_when_money_returns
     *
     * @effects exchange_requires_account_only_when_money_returns
     *
     * @return void 반환값 없음
     */
    public function test_same_price_exchange_does_not_require_an_account(): void
    {
        $order = $this->myBankTransferOrder();

        $response = $this->actingAs($this->user)
            ->postJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/exchanges", [
                'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
                'reason_code' => 'defective_product',
            ]);

        $this->assertNotContains('refund_bank', array_keys((array) $response->json('errors')));
    }

    /**
     * `claimable-options` 가 등록 계좌를 가린 모양으로 내고, 손대지 않은 제출은 원문으로 저장되는지 검증합니다.
     *
     * 수정 전: 응답이 원문을 실어(비회원 조회 토큰만으로 계좌 원문 노출), 가린 값을 되돌리는 경로가 없다.
     *
     * @scenario defect=claimable_options_masks_registered_account_and_restores_on_submit
     *
     * @effects claimable_options_masks_registered_account_and_restores_on_submit
     *
     * @return void 반환값 없음
     */
    public function test_claimable_options_masks_the_registered_account_and_restores_it_on_submit(): void
    {
        $order = $this->myBankTransferOrder([
            'refund_bank_code' => '004',
            'refund_bank_account' => '110234567890',
            'refund_bank_holder' => '홍길동',
        ]);

        $prefill = $this->actingAs($this->user)
            ->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/claimable-options")
            ->assertOk()
            ->json('data.refund_account.prefill');

        $this->assertSame('004', $prefill['bank_code']);
        $this->assertStringNotContainsString('110234567890', (string) $prefill['account_number']);
        $this->assertStringNotContainsString('홍길동', (string) $prefill['holder']);

        $this->actingAs($this->user)
            ->postJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/returns", [
                'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
                'reason_code' => 'changed_mind',
                'charge_method' => 'deduct_from_refund',
                'refund_bank' => [
                    'bank_code' => $prefill['bank_code'],
                    'account_number' => $prefill['account_number'],
                    'holder' => $prefill['holder'],
                ],
            ])
            ->tap(fn ($r) => $this->assertSame(201, $r->getStatusCode(), (string) $r->getContent()));

        $return = OrderReturn::latest('id')->firstOrFail();
        $this->assertSame('110234567890', $return->refund_bank_account, '가린 값이 원문으로 되돌려지지 않았다');
        $this->assertSame('홍길동', $return->refund_bank_holder);
    }

    /**
     * 가린 등록 계좌에서 한 칸만 고쳐 별표가 섞인 채 제출하면 422 로 거절되고 아무것도 저장되지 않는지 검증합니다.
     *
     * 수정 전: 복원 대상이 아닌 별표 섞인 값(예금주만 바꿈 · 계좌번호 뒤에 한 글자 덧붙임)이 검증을 통과해
     * `********7890` 같은 계좌번호가 신청서에 그대로 저장된다.
     *
     * @scenario defect=masked_refund_bank_fragments_are_rejected
     *
     * @effects masked_refund_bank_fragments_are_rejected
     *
     * @return void 반환값 없음
     */
    public function test_partially_edited_masked_account_is_rejected(): void
    {
        $order = $this->myBankTransferOrder([
            'refund_bank_code' => '004',
            'refund_bank_account' => '110234567890',
            'refund_bank_holder' => '홍길동',
        ]);

        $prefill = $this->actingAs($this->user)
            ->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/claimable-options")
            ->json('data.refund_account.prefill');

        $cases = [
            // 예금주만 새로 적고 계좌번호는 가린 값 그대로
            ['bank_code' => '004', 'account_number' => $prefill['account_number'], 'holder' => '김철수'],
            // 가린 계좌번호 뒤에 한 글자를 덧붙임
            ['bank_code' => '004', 'account_number' => $prefill['account_number'].'1', 'holder' => $prefill['holder']],
        ];

        foreach ($cases as $bank) {
            $this->actingAs($this->user)
                ->postJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/returns", [
                    'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
                    'reason_code' => 'changed_mind',
                    'charge_method' => 'deduct_from_refund',
                    'refund_bank' => $bank,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('refund_bank')
                ->assertJsonPath('message', __('sirsoft-ecommerce::validation.claims.refund_bank.masked'));
        }

        $this->assertSame(0, OrderReturn::count());
    }

    /**
     * 수정 요청도 신청서 계좌를 가린 모양 그대로면 통과시키고, 한 칸만 고친 별표 섞인 값은 거절하는지 검증합니다.
     *
     * 수정 전: 예금주만 바꾼 요청이 검증을 통과해 가린 계좌번호가 신청서에 저장된다.
     *
     * @scenario defect=masked_refund_bank_fragments_are_rejected
     *
     * @effects masked_refund_bank_fragments_are_rejected
     *
     * @return void 반환값 없음
     */
    public function test_claim_update_rejects_partially_edited_masked_account(): void
    {
        $order = $this->myBankTransferOrder();

        $number = $this->actingAs($this->user)
            ->postJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/returns", [
                'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
                'reason_code' => 'changed_mind',
                'charge_method' => 'deduct_from_refund',
                'refund_bank' => ['bank_code' => '004', 'account_number' => '110234567890', 'holder' => '홍길동'],
            ])
            ->tap(fn ($r) => $this->assertSame(201, $r->getStatusCode(), (string) $r->getContent()))
            ->json('data.claim.claim_number');

        // 표시 응답의 가린 계좌를 그대로 되돌려 보내면 "바꾸지 않음" 이다.
        $this->actingAs($this->user)
            ->patchJson("/api/modules/sirsoft-ecommerce/user/claims/{$number}", [
                'reason_detail' => '그대로 둠',
                'refund_bank' => ['bank_code' => '004', 'account_number' => '********7890', 'holder' => '홍**'],
            ])
            ->assertOk();

        $this->actingAs($this->user)
            ->patchJson("/api/modules/sirsoft-ecommerce/user/claims/{$number}", [
                'refund_bank' => ['bank_code' => '004', 'account_number' => '********7890', 'holder' => '김철수'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('refund_bank');

        $return = OrderReturn::where('return_number', $number)->firstOrFail();
        $this->assertSame('110234567890', $return->refund_bank_account);
        $this->assertSame('홍길동', $return->refund_bank_holder);
    }

    /**
     * 클레임 권한만 가진 운영자도 환불 계좌 원문을 보는지 검증합니다.
     *
     * 수정 전: 원문 열람 게이트가 `orders.read` 하나라 환불을 실행할 운영자가 가린 계좌만 본다.
     *
     * @scenario defect=claim_permission_holders_see_raw_refund_account
     *
     * @effects claim_permission_holders_see_raw_refund_account
     *
     * @return void 반환값 없음
     */
    public function test_claim_permission_holders_see_the_raw_refund_account(): void
    {
        $refund = $this->approvedRefund('dbank', RefundMethodEnum::BANK);
        $refund->update(['refund_bank_code' => '004', 'refund_bank_account' => '11122233344', 'refund_bank_holder' => '홍길동']);

        $this->actingAs($this->refundExecutor())
            ->getJson("/api/modules/sirsoft-ecommerce/admin/refunds/{$refund->id}")
            ->assertOk()
            ->assertJsonPath('data.claim.refund_bank_account', '11122233344')
            ->assertJsonPath('data.claim.refund_bank_holder', '홍길동');
    }

    /**
     * 신청 수정이 접수와 같은 사유 게이트(그 유형의 활성·고객 선택 사유)를 거치는지 검증합니다.
     *
     * 수정 전: 수정 요청이 사유를 `max:30` 문자열로만 받아 다른 유형·없는 사유로 바꿀 수 있다.
     *
     * @scenario defect=claim_update_validates_reason_like_request
     *
     * @effects claim_update_validates_reason_like_request
     *
     * @return void 반환값 없음
     */
    public function test_claim_update_validates_the_reason_like_the_request(): void
    {
        $order = $this->myBankTransferOrder([
            'refund_bank_code' => '004',
            'refund_bank_account' => '110234567890',
            'refund_bank_holder' => '홍길동',
        ]);

        $number = $this->actingAs($this->user)
            ->postJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/returns", [
                'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
                'reason_code' => 'changed_mind',
                'charge_method' => 'deduct_from_refund',
            ])
            ->tap(fn ($r) => $this->assertSame(201, $r->getStatusCode(), (string) $r->getContent()))
            ->json('data.claim.claim_number');

        $this->actingAs($this->user)
            ->patchJson("/api/modules/sirsoft-ecommerce/user/claims/{$number}", ['reason_code' => 'no_such_reason'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason_code');

        // 환불신청서 전용 필드(청구 방식 없음)가 아닌 교환 전용 축(재발송지)은 반품에서 거절된다.
        $this->actingAs($this->user)
            ->patchJson("/api/modules/sirsoft-ecommerce/user/claims/{$number}", [
                'redelivery_address' => ['recipient_name' => '홍길동'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('redelivery_address');
    }
}
