<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use App\Contracts\Extension\StorageInterface;
use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Mockery;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 회원 클레임 API Feature 테스트 (S5)
 *
 * 검증 축 (회원 + 인가):
 * - 신청 가능 옵션 / 미리보기 — 본인 주문만(404), 자격 위반은 422 + 사유 키
 * - 신청 3종 — 교환(첨부 귀속 + 신청 시점 결제 진입 4키) / 반품 / 취소·환불 자동 분기(즉시취소 · 환불신청서 · 혼재 422)
 * - 목록(유형 서브탭·페이지네이션) / 상세(결제 블록) / 수정(PATCH, 승인 뒤 422) / 철회(환급)
 * - 첨부 업로드·삭제 / 추가결제 pay(마일리지)·approve(PG 훅)
 * - 주문 상세의 `claim_additional_paid_total` / 예외 → 상태코드 페어(도메인 422 · 인프라 500)
 */
class UserClaimApiTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected User $user;

    /**
     * 통화·사유를 심고 회원을 만든 뒤, PG 환불 훅과 첨부 저장소를 성공으로 고정합니다.
     *
     * 이 클래스가 재는 것은 API 계약이지 PG·디스크가 붙었는가가 아니다 — 고정하지 않으면
     * 전 축이 환경 때문에 같은 이유로 실패해 축이 무의미해진다.
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
            'success' => true, 'transaction_id' => 'TEST_TXN', 'error_code' => null, 'error_message' => null,
        ]);

        $storage = Mockery::mock(StorageInterface::class);
        $storage->allows('put')->andReturn(true)->byDefault();
        $storage->allows('delete')->andReturn(true)->byDefault();
        $storage->allows('exists')->andReturn(true)->byDefault();
        $storage->allows('getDisk')->andReturn('local')->byDefault();
        $storage->allows('response')->andReturn(null)->byDefault();
        $this->app->when(ClaimAttachmentService::class)->needs(StorageInterface::class)->give(fn () => $storage);
    }

    /**
     * 테스트가 심은 통화 설정과 목(mock)을 정리합니다.
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
     * 회원 클레임 API 의 접두 경로를 돌려줍니다.
     *
     * @return string API 접두 경로
     */
    protected function base(): string
    {
        return '/api/modules/sirsoft-ecommerce/user';
    }

    /**
     * 이 회원의 배송완료 주문을 만듭니다.
     *
     * @param  int  $quantity  주문옵션 수량
     * @param  int  $returnFee  회수 배송비(반품 축의 청구액 기준)
     * @return Order 배송완료 주문 (options·shippingAddress 로드됨)
     */
    protected function myDeliveredOrder(int $quantity = 1, int $returnFee = 3000): Order
    {
        $order = $this->deliveredClaimOrder(quantity: $quantity, returnFee: $returnFee);
        $order->forceFill(['user_id' => $this->user->id])->save();

        return $order->fresh(['options', 'shippingAddress']);
    }

    /**
     * 이 회원의 결제완료 주문을 만듭니다.
     *
     * @param  array  $paymentOverrides  OrderPayment 오버라이드 (결제수단 게이트 축 — P16)
     * @return Order 결제완료 주문 (options 로드됨)
     */
    protected function myPaidOrder(array $paymentOverrides = []): Order
    {
        $order = $this->paidClaimOrder(paymentOverrides: $paymentOverrides);
        $order->forceFill(['user_id' => $this->user->id])->save();

        return $order->fresh(['options']);
    }

    /**
     * 주문의 첫 옵션 하나를 신청 항목 배열로 만듭니다.
     *
     * @param  Order  $order  대상 주문
     * @param  int  $quantity  신청 수량
     * @return array<int, array{order_option_id: int, quantity: int}> 신청 항목 배열
     */
    protected function items(Order $order, int $quantity = 1): array
    {
        return [['order_option_id' => $order->options->first()->id, 'quantity' => $quantity]];
    }

    // ================================================================
    // 신청 가능 옵션 / 미리보기
    // ================================================================

    /**
     * 신청 가능 옵션 응답이 유형·사유·설정·대기 첨부를 한 묶음으로 낸다
     *
     * @return void 반환값 없음
     *
     * @effects claimable_options_lists_types_reasons_and_settings
     */
    public function test_claimable_options_lists_types_reasons_and_settings(): void
    {
        $order = $this->myDeliveredOrder();

        $response = $this->actingAs($this->user)->getJson($this->base()."/orders/{$order->id}/claimable-options");

        $response->assertOk()
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.options.0.available_quantity', 1)
            ->assertJsonPath('data.options.0.claimable_types', ['exchange', 'return'])
            ->assertJsonPath('data.options.0.refund_branch', null)
            ->assertJsonPath('data.resolved_type', null)
            ->assertJsonPath('data.settings.max_images', 5)
            ->assertJsonStructure(['data' => ['reasons' => ['exchange', 'return', 'refund'], 'settings' => ['allowed_charge_methods', 'charge_payment_methods'], 'pending_images']]);
    }

    /**
     * PG 결제 주문은 환불계좌를 요구하지 않는다. (§6-3 — 무통장 원결제면 환불계좌 입력)
     *
     * 어느 결제수단이 계좌 환불인지는 서버가 정한다. 화면이 결제수단 코드로 재현하면 확장
     * 결제수단이 자기 카탈로그에 선언한 환불 수단과 갈리고, 어긋난 쪽은 오류 없이 계좌
     * 입력칸이 없는 계좌 환불이 되거나 쓸모없는 계좌를 요구한다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=request
     *
     * @effects claim_refund_account_requirement_declared_by_server
     */
    public function test_claimable_options_does_not_require_a_refund_account_for_pg_payments(): void
    {
        $order = $this->myDeliveredOrder();

        $this->actingAs($this->user)->getJson($this->base()."/orders/{$order->id}/claimable-options")
            ->assertOk()
            ->assertJsonPath('data.refund_account.required', false)
            // 필요하지 않은 주문에는 은행 목록을 싣지 않는다 — 목록 자체가 입력칸의 근거다.
            ->assertJsonPath('data.refund_account.banks', []);
    }

    /**
     * 무통장 결제 주문은 환불계좌를 요구하고, 운영자가 등록한 은행 목록을 함께 낸다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects claim_refund_account_requirement_declared_by_server,
     *   claim_refund_account_banks_come_from_operator_settings
     */
    public function test_claimable_options_requires_a_refund_account_for_bank_transfer_payments(): void
    {
        $order = $this->deliveredClaimOrder(paymentOverrides: ['payment_method' => PaymentMethodEnum::DBANK->value]);
        $order->forceFill(['user_id' => $this->user->id])->save();

        $response = $this->actingAs($this->user)->getJson($this->base()."/orders/{$order->id}/claimable-options")
            ->assertOk()
            ->assertJsonPath('data.refund_account.required', true);

        $banks = $response->json('data.refund_account.banks');

        $this->assertNotEmpty($banks, '운영자 주문설정의 은행 목록이 화면에 도달해야 계좌 선택칸이 선다');
        $this->assertArrayHasKey('code', $banks[0]);
        $this->assertArrayHasKey('name', $banks[0]);
    }

    /**
     * 운영자가 은행 목록을 비워 두어도 **계좌를 고를 수 있어야 한다** — 모듈 기본 목록으로 떨어진다.
     *
     * 계좌 환불 주문은 계좌 없이 접수되지 않는다(T2-D5). 그런데 선택지가 0개면 구매자는 칸을 채울
     * 방법이 없어 **환불 신청 자체가 영영 막힌다** — 2026-09-23 재실측에서 개발 상점의
     * `order_settings.banks` 가 빈 배열이라 [주문 취소하기] 가 계좌를 적어도 풀리지 않았다
     * (은행 선택 칸에 자리표시자만 있었다). 필수 칸을 만든 이상 그 칸을 채울 길도 함께 보장한다.
     *
     * 기본 목록은 모듈이 싣고 있는 것(`config/settings/defaults.json`)이다 — 운영자가 한 곳이라도
     * 등록하면 그 목록을 그대로 쓴다(위 테스트).
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects claim_refund_account_banks_fall_back_to_the_module_defaults
     */
    public function test_refund_account_banks_fall_back_to_the_module_defaults_when_the_operator_list_is_empty(): void
    {
        $settings = app(EcommerceSettingsService::class);
        $previous = $settings->getSetting('order_settings.banks');
        $settings->setSetting('order_settings.banks', []);

        try {
            $order = $this->deliveredClaimOrder(paymentOverrides: ['payment_method' => PaymentMethodEnum::DBANK->value]);
            $order->forceFill(['user_id' => $this->user->id])->save();

            $banks = $this->actingAs($this->user)->getJson($this->base()."/orders/{$order->id}/claimable-options")
                ->assertOk()
                ->assertJsonPath('data.refund_account.required', true)
                ->json('data.refund_account.banks');

            $this->assertNotEmpty($banks, '필수인 계좌 칸에 고를 은행이 하나도 없다 — 환불 신청이 영영 막힌다');
            $this->assertContains('004', array_column($banks, 'code'));
        } finally {
            app(EcommerceSettingsService::class)->setSetting('order_settings.banks', $previous);
        }
    }

    /**
     * 무통장 주문의 신청 가능 옵션은 «자동으로 끝나지 않는다» 는 사실을 함께 낸다. (P16·P17)
     *
     * 즉시취소 분기(`refund_branch=auto`)와 결제수단 게이트는 다른 축이다. 무통장·가상계좌는
     * 분기가 auto 여도 접수 뒤 `requested` 에서 멈추고 운영자 승인 → [환불하기] 를 기다린다.
     * 그 사실이 응답에 없으면 신청서는 분기만 보고 「자동 처리」라고 안내하게 되고, 구매자는
     * 곧 돈이 돌아온다고 읽는다(실측 2026-09-21: 주문 20260921-1316400577 배지 「자동 처리」 ·
     * 안내 「승인·환불완료까지 자동으로 진행됩니다」 → 실제 RF232 는 `requested` 정지).
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects claim_refund_auto_approval_gate_declared_by_server
     *
     * @return void 반환값 없음
     */
    public function test_claimable_options_declares_that_bank_transfer_refunds_are_not_auto_approved(): void
    {
        $order = $this->deliveredClaimOrder(paymentOverrides: ['payment_method' => PaymentMethodEnum::DBANK->value]);
        $order->forceFill(['user_id' => $this->user->id])->save();

        $this->actingAs($this->user)->getJson($this->base()."/orders/{$order->id}/claimable-options")
            ->assertOk()
            ->assertJsonPath('data.refund_auto_approved', false);
    }

    /**
     * 카드 주문은 반대로 자동 승인 대상이라고 낸다. (P16·P17 대조군)
     *
     * 대조군이 없으면 「언제나 false」 로 굳어도 테스트가 통과한다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects claim_refund_auto_approval_gate_declared_by_server
     *
     * @return void 반환값 없음
     */
    public function test_claimable_options_declares_that_card_refunds_are_auto_approved(): void
    {
        $order = $this->deliveredClaimOrder(paymentOverrides: ['payment_method' => PaymentMethodEnum::CARD->value]);
        $order->forceFill(['user_id' => $this->user->id])->save();

        $this->actingAs($this->user)->getJson($this->base()."/orders/{$order->id}/claimable-options")
            ->assertOk()
            ->assertJsonPath('data.refund_auto_approved', true);
    }

    /**
     * 남의 주문의 신청 가능 옵션은 404 (존재 비노출)
     *
     * @return void 반환값 없음
     *
     * @effects claim_endpoints_scope_to_the_requesting_member
     */
    public function test_claimable_options_is_404_for_someone_elses_order(): void
    {
        $order = $this->deliveredClaimOrder();

        $this->actingAs($this->user)->getJson($this->base()."/orders/{$order->id}/claimable-options")->assertNotFound();
    }

    /**
     * 미리보기가 청구액·배송비 추정을 내고, 대상이 아닌 상품은 신청과 같은 사유로 422
     *
     * @return void 반환값 없음
     *
     * @effects claim_preview_returns_the_estimate_and_rejects_ineligible_items
     */
    public function test_preview_returns_the_estimate_and_rejects_ineligible_items_with_422(): void
    {
        $order = $this->myDeliveredOrder();

        $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/claims/preview", [
            'type' => 'return', 'items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'pay_on_request',
        ])->assertOk()
            ->assertJsonPath('data.type', 'return')
            ->assertJsonPath('data.total_charge_amount', 3000)
            ->assertJsonPath('data.requires_payment', true)
            ->assertJsonPath('data.shipping_fee.fee', 3000);

        // 배송완료 전 상품은 반품 신청 대상이 아니다 — 미리보기도 신청과 같은 사유로 거절한다.
        $paid = $this->myPaidOrder();
        $this->actingAs($this->user)->postJson($this->base()."/orders/{$paid->id}/claims/preview", [
            'type' => 'return', 'items' => $this->items($paid), 'reason_code' => 'changed_mind',
        ])->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_option_not_claimable', ['type' => ClaimTypeEnum::RETURN->label(), 'status' => OrderStatusEnum::PAYMENT_COMPLETE->label()]));
    }

    // ================================================================
    // 신청 3종
    // ================================================================

    /**
     * 교환 접수가 대기 첨부를 신청서에 붙이고 결제대기 + PG 결제 계약을 낸다
     *
     * @return void 반환값 없음
     *
     * @effects exchange_request_attaches_images_and_enters_payment_pending
     */
    public function test_exchange_request_attaches_pending_images_and_enters_payment(): void
    {
        $this->registerTestPgProvider();
        $order = $this->myDeliveredOrder();
        $image = app(ClaimAttachmentService::class)->uploadPending(UploadedFile::fake()->image('a.jpg'), $order, $this->user->id);

        $response = $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/exchanges", [
            'items' => $this->items($order),
            'reason_code' => 'size_mismatch',
            'charge_method' => 'pay_on_request',
            'payment_method' => 'card',
            'image_ids' => [$image->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.claim.claim_type', 'exchange')
            ->assertJsonPath('data.claim.status', 'payment_pending')
            ->assertJsonPath('data.claim.total_charge_amount', 6000)
            ->assertJsonPath('data.claim.images.0.id', $image->id)
            ->assertJsonPath('data.requires_pg_payment', true)
            ->assertJsonPath('data.pg_payment_handler', 'sirsoft-testpg.requestPayment')
            ->assertJsonPath('data.pg_payment_data.amount', 6000)
            ->assertJsonPath('data.payment.payment_status', 'pending');

        $this->assertSame('exchange', $image->fresh()->claimable_type);
        $this->assertSame($response->json('data.pg_payment_data.order_number'), $response->json('data.payment.payment_number'));
        $this->assertStringStartsWith('/api/modules/sirsoft-ecommerce/claim-image/', $response->json('data.claim.images.0.download_url'));
    }

    /**
     * 환불금 차감 반품은 접수 상태로 서고 결제 건을 만들지 않는다
     *
     * @return void 반환값 없음
     *
     * @effects return_request_with_deduct_from_refund_creates_no_payment
     */
    public function test_return_request_with_deduct_from_refund_needs_no_payment(): void
    {
        $order = $this->myDeliveredOrder();

        $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/returns", [
            'items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund',
        ])->assertCreated()
            ->assertJsonPath('data.claim.claim_type', 'return')
            ->assertJsonPath('data.claim.status', 'requested')
            ->assertJsonPath('data.requires_pg_payment', false)
            ->assertJsonPath('data.payment', null);

        $this->assertSame(1, OrderReturn::count());
        $this->assertSame(0, ClaimPayment::count());
    }

    /**
     * 반품도 환불계좌를 받고, 그 값은 **그 신청서 행**에 적힌다. (§6-3 · 2026-08-31 확정)
     *
     * 신청서마다 자기 계좌를 든다 — 주문할 때 남긴 계좌를 채워서 열되 그 자리에서 고칠 수 있으므로,
     * 신청서가 자기 값을 들지 않으면 어느 계좌로 신청했는지가 남지 않는다.
     *
     * 주문 결제행은 **실제 송금 대상**이라 신청 접수만으로는 바뀌지 않는다. 접수 때 덮어쓰면 아직
     * 나가지도 않은 신청이 주문 전체의 송금 대상을 바꾼다 — 그 갱신은 환불을 실행하는 시점의 일이다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, charge_method=deduct_from_refund, refund_branch=none
     *
     * @effects claim_refund_account_recorded_on_the_claim_itself
     */
    public function test_return_request_records_the_refund_account_on_the_return_itself(): void
    {
        $order = $this->myDeliveredOrder();

        $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/returns", [
            'items' => $this->items($order),
            'reason_code' => 'changed_mind',
            'charge_method' => 'deduct_from_refund',
            'refund_bank' => ['bank_code' => '004', 'account_number' => '110234567890', 'holder' => '홍길동'],
        ])->assertCreated();

        $return = OrderReturn::firstOrFail();

        $this->assertSame('004', $return->refund_bank_code);
        $this->assertSame('110234567890', $return->refund_bank_account);
        $this->assertSame('홍길동', $return->refund_bank_holder);

        // 송금 대상은 아직 그대로다 — 접수는 실행이 아니다.
        $payment = $order->fresh('payment')->payment;

        $this->assertNull($payment->refund_bank_code);
        $this->assertNull($payment->refund_bank_account);
        $this->assertNull($payment->refund_bank_holder);
    }

    /**
     * 교환도 환불계좌를 받고, 그 값은 교환 신청서 행에 적힌다.
     *
     * 저가 옵션으로 바꾸면 차액이 구매자에게 돌아간다 — 그 돈이 계좌로 나가는 주문이면 반품·취소·
     * 환불과 같은 자리·같은 강도로 받아야 한다. 교환만 받지 않으면 그 차액을 어느 계좌로 보낼지가
     * 주문 당시 값뿐이라, 구매자가 계좌를 바꾼 경우 운영자가 따로 물어야 한다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, charge_method=pay_on_request, refund_branch=none
     *
     * @effects claim_refund_account_recorded_on_the_claim_itself
     */
    public function test_exchange_request_records_the_refund_account_on_the_exchange_itself(): void
    {
        $this->registerTestPgProvider();
        $order = $this->myDeliveredOrder();

        $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/exchanges", [
            'items' => $this->items($order),
            'reason_code' => 'size_mismatch',
            'charge_method' => 'pay_on_request',
            'payment_method' => 'card',
            'refund_bank' => ['bank_code' => '004', 'account_number' => '110234567890', 'holder' => '홍길동'],
        ])->assertCreated();

        $exchange = OrderExchange::firstOrFail();

        $this->assertSame('004', $exchange->refund_bank_code);
        $this->assertSame('110234567890', $exchange->refund_bank_account);
        $this->assertSame('홍길동', $exchange->refund_bank_holder);

        $this->assertNull($order->fresh('payment')->payment->refund_bank_code);
    }

    /**
     * 교환의 환불계좌도 세 필드가 함께 와야 한다.
     *
     * 형제 경로(취소·환불·반품)가 이미 같은 규칙을 갖는다 — 한 유형만 약하면 그 유형이 우회로가 된다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, charge_method=pay_on_request, refund_branch=none
     *
     * @effects claim_refund_account_requires_all_three_fields
     */
    public function test_exchange_request_rejects_a_partially_filled_refund_account(): void
    {
        $order = $this->myDeliveredOrder();

        $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/exchanges", [
            'items' => $this->items($order),
            'reason_code' => 'size_mismatch',
            'charge_method' => 'pay_on_request',
            'payment_method' => 'card',
            'refund_bank' => ['bank_code' => '004'],
        ])->assertStatus(422)->assertJsonValidationErrors(['refund_bank']);

        $this->assertSame(0, OrderExchange::count());
    }

    /**
     * 반품의 환불계좌도 세 필드가 함께 와야 한다. (하나만 오면 계좌 없는 무통장 환불이 승인된다)
     *
     * 취소·환불이 이미 같은 규칙을 갖는다 — 형제 경로의 강도가 다르면 약한 쪽이 우회로가 된다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, charge_method=deduct_from_refund, refund_branch=none
     *
     * @effects claim_refund_account_requires_all_three_fields
     */
    public function test_return_request_rejects_a_partially_filled_refund_account(): void
    {
        $order = $this->myDeliveredOrder();

        $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/returns", [
            'items' => $this->items($order),
            'reason_code' => 'changed_mind',
            'charge_method' => 'deduct_from_refund',
            'refund_bank' => ['bank_code' => '004'],
        ])->assertStatus(422)->assertJsonValidationErrors(['refund_bank']);

        $this->assertSame(0, OrderReturn::count());
    }

    /**
     * 주문 시 등록해 둔 계좌가 있으면 신청 화면이 채울 수 있게 **가린 모양**으로 함께 낸다.
     *
     * 이 응답은 비회원 조회 토큰을 가진 누구에게나 나가므로 원문을 싣지 않는다(#599 validate P3).
     * 구매자가 손대지 않고 제출하면 가린 값이 그대로 돌아오고, 서버가 등록 계좌를 가린 모양과
     * 같을 때만 원문으로 되돌린다 — 그 왕복은 `ValidateP3RefundAccountTest` 가 잰다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects claim_refund_account_prefills_the_registered_account
     */
    public function test_claimable_options_prefills_the_registered_refund_account(): void
    {
        $order = $this->deliveredClaimOrder(paymentOverrides: [
            'payment_method' => PaymentMethodEnum::DBANK->value,
            'refund_bank_code' => '004',
            'refund_bank_account' => '110234567890',
            'refund_bank_holder' => '홍길동',
        ]);
        $order->forceFill(['user_id' => $this->user->id])->save();

        $this->actingAs($this->user)->getJson($this->base()."/orders/{$order->id}/claimable-options")
            ->assertOk()
            ->assertJsonPath('data.refund_account.required', true)
            ->assertJsonPath('data.refund_account.prefill.bank_code', '004')
            // 원문이 아니라 가린 모양이다 — 뒤 4자리·예금주 첫 글자만 남는다.
            ->assertJsonPath('data.refund_account.prefill.account_number', '********7890')
            ->assertJsonPath('data.refund_account.prefill.holder', '홍**')
            ->assertJsonPath('data.refund_account.prefill.masked', true);
    }

    /**
     * 등록된 계좌가 없으면 프리필은 비어 있다. (대조군 — 화면은 빈 칸을 연다)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects claim_refund_account_prefills_the_registered_account
     */
    public function test_claimable_options_has_no_prefill_without_a_registered_account(): void
    {
        $order = $this->deliveredClaimOrder(paymentOverrides: ['payment_method' => PaymentMethodEnum::DBANK->value]);
        $order->forceFill(['user_id' => $this->user->id])->save();

        $this->actingAs($this->user)->getJson($this->base()."/orders/{$order->id}/claimable-options")
            ->assertOk()
            ->assertJsonPath('data.refund_account.required', true)
            ->assertJsonPath('data.refund_account.prefill', null);
    }

    /**
     * 다른 주문의 상품을 담으면 422, 남의 주문에 접수하면 404
     *
     * @return void 반환값 없음
     *
     * @effects claim_request_rejects_items_outside_the_order_with_422, claim_endpoints_scope_to_the_requesting_member
     */
    public function test_return_request_of_a_foreign_item_is_422_and_someone_elses_order_is_404(): void
    {
        $order = $this->myDeliveredOrder();
        $foreign = $this->deliveredClaimOrder();

        $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/returns", [
            'items' => $this->items($foreign), 'reason_code' => 'changed_mind',
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.order_option_id']);

        $this->actingAs($this->user)->postJson($this->base()."/orders/{$foreign->id}/returns", [
            'items' => $this->items($foreign), 'reason_code' => 'changed_mind',
        ])->assertNotFound();
    }

    /**
     * 결제완료 상품은 `auto` 분기다 — **환불신청서를 만들고** 승인·완료까지 자동으로 간다.
     *
     * 회귀 잠금(#599): 예전에는 환불신청서 없이 취소 기록만 남았고, 그 환불 행은 목록에서
     * 배제돼 고객에게 **환불 이력이 아예 보이지 않았다**. 접수 결과가 언제나 환불신청서라야
     * 화면이 그 경과를 보여 줄 수 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, payment_state=card_paid
     *
     * @effects cancellation_record_is_created_only_on_refund_completion
     */
    public function test_refund_request_branches_to_auto_advance_for_paid_items(): void
    {
        $order = $this->myPaidOrder();

        $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/refund-requests", [
            'items' => $this->items($order), 'reason_code' => 'changed_mind',
        ])->assertCreated()
            ->assertJsonPath('data.branch', 'auto')
            // 접수 결과는 **언제나 환불신청서**다 (D2).
            ->assertJsonPath('data.claim.claim_type', 'refund')
            ->assertJsonPath('data.claim.refund_source_type', 'direct');

        // 취소신청서는 환불이 완료되는 순간 함께 생긴다 (D3).
        $this->assertSame(1, OrderRefund::count());
        $this->assertSame(1, OrderCancel::where('order_id', $order->id)->count());
        $this->assertSame(OrderStatusEnum::CANCELLED, $order->fresh()->order_status);
    }

    /**
     * F7 — 자동 전이 분기에서도 **구매자 첨부가 살아남는다**.
     *
     * 예전에는 즉시취소 분기에 귀속할 신청서가 없어 첨부를 말없이 지웠다. 고객이 증빙으로
     * 올린 사진이 접수 직후 사라지고, 그 사실은 응답에도 로그에도 남지 않았다 — 나중에 분쟁이
     * 나면 근거가 없다. 새 모델은 자동 전이 분기도 환불신청서를 만들므로 귀속 대상이 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects auto_branch_keeps_the_buyer_attachments
     */
    public function test_the_auto_branch_keeps_the_buyer_attachments(): void
    {
        $order = $this->myPaidOrder();
        $image = app(ClaimAttachmentService::class)->uploadPending(
            UploadedFile::fake()->image('evidence.jpg'),
            $order,
            $this->user->id,
        );

        $response = $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/refund-requests", [
            'items' => $this->items($order),
            'reason_code' => 'changed_mind',
            'image_ids' => [$image->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.branch', 'auto')
            ->assertJsonPath('data.claim.images.0.id', $image->id);

        // 파일도 귀속도 남아 있어야 한다 — 지워지면 둘 다 사라진다.
        $fresh = $image->fresh();
        $this->assertNotNull($fresh, '자동 전이 분기가 구매자 첨부를 지웠다 (F7)');
        $this->assertSame('refund', $fresh->claimable_type);
    }

    /**
     * 무통장 결제완료 주문은 `auto` 분기지만 **환불신청 상태로 접수된다**. (P16)
     *
     * 분기(`auto`)와 종착점(`requested`)이 다르다는 것이 이 축의 핵심이다 — 분기는 "어느 입구로
     * 들어왔나"(즉시취소 목록 상태)이고, 종착점은 "돈이 사람 손 없이 돌아가나"(결제수단)가
     * 정한다. 둘을 한 값으로 묶으면 무통장 결제완료 주문에서 선택지가 사라진다: 분기를
     * `request` 로 바꾸면 화면이 "배송 준비 중이라 신청합니다" 로 잘못 안내하고, 종착점을
     * `completed` 로 두면 돈이 나가기 전에 환불완료 알림이 간다.
     *
     * 계좌를 실어 보내는 것은 이 축과 **직교하는 다른 계약**이다 — 돈이 계좌로 돌아가는 주문은
     * 접수 시점에 받을 계좌가 있어야 한다(T2-D5, 운영 결정 2026-09-23). 무통장은 바로 그런
     * 주문이라 계좌 없이는 422 로 막히고, 그 경로는
     * `UserClaimRefundBranchTest::test_bank_refund_is_rejected_without_an_account` 가 잠근다.
     *
     * @return void 반환값 없음
     *
     * @scenario gate_case=stopped_method_user_api
     *
     * @effects stopped_payment_method_holds_refund_at_requested
     */
    public function test_refund_request_of_a_bank_transfer_order_stops_at_requested(): void
    {
        $order = $this->myPaidOrder(['payment_method' => 'dbank']);

        $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/refund-requests", [
            'items' => $this->items($order),
            'reason_code' => 'changed_mind',
            'refund_bank' => ['bank_code' => '003', 'account_number' => '01012345678', 'holder' => 'MEAS599'],
        ])->assertCreated()
            // 입구는 그대로 즉시취소 분기다 — 결제완료 상품이므로.
            ->assertJsonPath('data.branch', 'auto')
            ->assertJsonPath('data.claim.claim_type', 'refund')
            // 그러나 사람이 송금해야 끝나므로 접수에서 멈춘다.
            ->assertJsonPath('data.claim.status', 'requested');

        $this->assertSame(1, OrderRefund::count());
        $this->assertSame(0, OrderCancel::where('order_id', $order->id)->count(), '환불 전에는 취소신청서가 없다 (D3)');
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->fresh()->order_status);
    }

    /**
     * 입금이 끝난 가상계좌 주문은 환불 계좌 없이 접수되지 않는다. (S1-D8)
     *
     * 막지 않으면 계좌 없이 접수·승인까지 통과한 뒤 마지막 [환불하기] 에서 PG 가
     * `MISSING_REFUND_ACCOUNT` 로 거절한다 — 그때는 이미 승인된 신청서라 화면에 계좌를 넣을
     * 자리가 없어 **운영자가 되돌릴 수 없다**. 관리자 [주문취소] 는 같은 판정을 이미 갖고 있었고
     * (`CancelOrderRequest::requiresRefundBank`) 고객 접수 경로에만 빠져 있었다.
     *
     * 실측(주문 20260915-0631411217 · RF20260915-0824214349): 계좌 없이 접수 201 → 승인 200 →
     * 실행 **422** `환불 실행에 실패했습니다.` · 로그 `MISSING_REFUND_ACCOUNT`.
     *
     * @return void 반환값 없음
     *
     * @scenario gate_case=vbank_paid_requires_refund_bank
     *
     * @effects refund_bank_required_before_intake_for_paid_vbank
     */
    public function test_refund_request_of_a_paid_vbank_order_requires_a_refund_bank(): void
    {
        $order = $this->myPaidOrder(['payment_method' => 'vbank']);

        $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/refund-requests", [
            'items' => $this->items($order), 'reason_code' => 'changed_mind',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('refund_bank');

        $this->assertSame(0, OrderRefund::where('order_id', $order->id)->count(), '계좌 없이 신청서가 만들어지면 안 된다');
    }

    /**
     * 환불 계좌를 실어 보내면 가상계좌 주문도 정상 접수된다. (S1-D8)
     *
     * @return void 반환값 없음
     *
     * @scenario gate_case=vbank_paid_with_refund_bank
     *
     * @effects refund_bank_required_before_intake_for_paid_vbank
     */
    public function test_refund_request_of_a_paid_vbank_order_passes_with_a_refund_bank(): void
    {
        $order = $this->myPaidOrder(['payment_method' => 'vbank']);

        $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/refund-requests", [
            'items' => $this->items($order),
            'reason_code' => 'changed_mind',
            'refund_bank' => [
                'bank_code' => '003',
                'account_number' => '01012345678',
                'holder' => 'MEAS599',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.branch', 'auto')
            // 가상계좌는 정지 수단이므로 접수에서 멈춘다 (P16)
            ->assertJsonPath('data.claim.status', 'requested');

        $this->assertSame(1, OrderRefund::where('order_id', $order->id)->count());
    }

    /**
     * 배송 전(준비중) 상품은 request 분기의 환불신청서가 되고 승인 전에는 취소신청서가 없다
     *
     * @return void 반환값 없음
     *
     * @effects refund_request_files_a_direct_refund_for_pre_shipping_items
     */
    public function test_refund_request_files_a_refund_request_for_preparing_items(): void
    {
        $order = $this->myPaidOrder();
        $order->options->first()->forceFill(['option_status' => OrderStatusEnum::PREPARING])->save();
        $order->forceFill(['order_status' => OrderStatusEnum::PREPARING])->save();

        $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/refund-requests", [
            'items' => $this->items($order), 'reason_code' => 'changed_mind', 'reason_detail' => '변심',
        ])->assertCreated()
            ->assertJsonPath('data.branch', 'request')
            ->assertJsonPath('data.claim.claim_type', 'refund')
            ->assertJsonPath('data.claim.status', 'requested')
            ->assertJsonPath('data.claim.refund_source_type', 'direct')
            ->assertJsonPath('data.claim.refund_total', 29000);

        $this->assertSame(1, OrderRefund::count());
        // 승인 대기 중에는 취소신청서가 없다 — 아직 돈이 나가지 않았다 (D3).
        $this->assertSame(0, OrderCancel::where('order_id', $order->id)->count());
    }

    /**
     * 배송완료 상품의 환불신청은 422 + 상태를 밝힌 문구
     *
     * @return void 반환값 없음
     *
     * @effects refund_request_of_a_delivered_item_is_422
     */
    public function test_refund_request_with_a_delivered_item_is_422(): void
    {
        $order = $this->myDeliveredOrder();

        $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/refund-requests", [
            'items' => $this->items($order), 'reason_code' => 'changed_mind',
        ])->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_refund_not_requestable', ['status' => OrderStatusEnum::DELIVERED->label()]));
    }

    // ================================================================
    // 목록 / 상세 / 수정 / 철회
    // ================================================================

    /**
     * 목록이 내 건만 유형별로 페이지네이션해 내고, 어휘 밖 유형은 422
     *
     * @return void 반환값 없음
     *
     * @effects user_claim_index_lists_only_my_claims_by_type_with_pagination
     */
    public function test_index_lists_claims_by_type_with_pagination(): void
    {
        $order = $this->myDeliveredOrder(quantity: 3);
        $claims = app(UserClaimService::class);
        $claims->requestReturn($order, ['items' => $this->items($order, 1), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'requested_by' => $this->user->id]);
        // 교환은 판매자 귀책(`defective_product`)으로 낸다 — 이 목록 테스트는 청구가 없는 신청서를
        // 전제한다. 고객 귀책이면 왕복 배송비가 청구되고 `deduct_from_refund` 는 차감 전 환불액이
        // 청구액보다 작을 때 거절된다(`OrderExchangeService::assertChargeMethodUsable`).
        $claims->requestExchange($order->fresh(['options']), ['items' => $this->items($order, 1), 'reason_code' => 'defective_product', 'charge_method' => 'deduct_from_refund', 'requested_by' => $this->user->id]);
        // 다른 회원의 반품은 보이지 않는다.
        $other = $this->deliveredClaimOrder();
        $claims->requestReturn($other, ['items' => $this->items($other), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'requested_by' => $other->user_id]);

        $this->actingAs($this->user)->getJson($this->base().'/claims?type=return')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.claim_type', 'return')
            ->assertJsonPath('data.data.0.order_number', $order->order_number)
            ->assertJsonPath('data.data.0.items.0.quantity', 1)
            ->assertJsonPath('data.pagination.total', 1);

        $this->actingAs($this->user)->getJson($this->base().'/claims?type=exchange&status=requested')
            ->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.is_editable', true);

        $this->actingAs($this->user)->getJson($this->base().'/claims?type=cancel')->assertOk()->assertJsonCount(0, 'data.data');
        $this->actingAs($this->user)->getJson($this->base().'/claims?type=bogus')->assertStatus(422);
    }

    /**
     * 목록이 **서브탭 건수**를 함께 돌려준다. (F12 — 브라우저 실측 회귀)
     *
     * 신청내역 화면의 서브탭(교환·반품·환불·취소)은 응답의 `counts` 로 뱃지를 단다. 그런데
     * 회원 목록만 그 키를 내지 않아 **뱃지가 영영 비어 있었다** — 구매자는 다른 탭에 건이
     * 있는지 알 수 없어 네 탭을 차례로 눌러 봐야 했다. 화면은 정상으로 보이고 오류도 없다
     * (주문 스코프 목록·관리자 목록은 같은 키를 이미 내고 있었다).
     *
     * 네 유형 전부를 늘 싣는다 — 키 부재를 0 으로 읽으면 "세지 않음" 과 "0건" 이 구분되지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, charge_method=deduct_from_refund, refund_branch=none
     *
     * @effects user_claim_index_returns_type_counts
     */
    public function test_index_returns_subtab_counts_for_every_type(): void
    {
        $order = $this->myDeliveredOrder(quantity: 3);
        $claims = app(UserClaimService::class);
        $claims->requestReturn($order, ['items' => $this->items($order, 1), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'requested_by' => $this->user->id]);
        $claims->requestExchange($order->fresh(['options']), ['items' => $this->items($order, 1), 'reason_code' => 'defective_product', 'charge_method' => 'deduct_from_refund', 'requested_by' => $this->user->id]);

        // 다른 회원의 건은 세지 않는다 — 뱃지가 남의 건을 포함하면 탭을 눌렀을 때 빈 목록이 나온다.
        $other = $this->deliveredClaimOrder();
        $claims->requestReturn($other, ['items' => $this->items($other), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'requested_by' => $other->user_id]);

        $this->actingAs($this->user)->getJson($this->base().'/claims?type=return')
            ->assertOk()
            ->assertJsonPath('data.counts.return', 1)
            ->assertJsonPath('data.counts.exchange', 1)
            ->assertJsonPath('data.counts.refund', 0)
            ->assertJsonPath('data.counts.cancel', 0)
            ->assertJsonPath('data.claim_type', 'return');
    }

    /**
     * 상태 필터는 **지금 보고 있는 탭**의 뱃지에만 걸린다. (F15 와 같은 규칙)
     *
     * 상태는 유형 종속 어휘라 다른 탭에 걸 수 없다 — 반품 상태를 교환 탭 건수에 걸면 그 탭이
     * 언제나 0 이 된다. 그래서 기간만 전 탭에 걸고 상태는 현재 탭만 다시 센다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, charge_method=deduct_from_refund, refund_branch=none
     *
     * @effects user_claim_index_counts_apply_status_to_current_tab_only
     */
    public function test_index_counts_apply_the_status_filter_to_the_current_tab_only(): void
    {
        $order = $this->myDeliveredOrder(quantity: 3);
        $claims = app(UserClaimService::class);
        $claims->requestReturn($order, ['items' => $this->items($order, 1), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'requested_by' => $this->user->id]);
        $claims->requestExchange($order->fresh(['options']), ['items' => $this->items($order, 1), 'reason_code' => 'defective_product', 'charge_method' => 'deduct_from_refund', 'requested_by' => $this->user->id]);

        // 반품 탭을 '완료' 로 좁힌다 — 접수 상태뿐이므로 반품 뱃지는 0, 교환 뱃지는 그대로 1.
        $this->actingAs($this->user)->getJson($this->base().'/claims?type=return&status=completed')
            ->assertOk()
            ->assertJsonPath('data.counts.return', 0)
            ->assertJsonPath('data.counts.exchange', 1);
    }

    /**
     * 상세가 결제 블록을 싣고 타인·없는 신청번호는 404
     *
     * @return void 반환값 없음
     *
     * @effects claim_detail_carries_payment_block_and_hides_other_members, claim_endpoints_scope_to_the_requesting_member
     */
    public function test_show_returns_the_detail_with_the_payment_block_and_hides_others(): void
    {
        $order = $this->myDeliveredOrder();
        $claims = app(UserClaimService::class);
        $result = $claims->requestReturn($order, ['items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'pay_on_request', 'requested_by' => $this->user->id], [], 'card');
        $return = $result['claim'];

        $this->actingAs($this->user)->getJson($this->base()."/claims/{$return->return_number}")
            ->assertOk()
            ->assertJsonPath('data.claim.claim_number', $return->return_number)
            ->assertJsonPath('data.claim.status', 'payment_pending')
            ->assertJsonPath('data.claim.outstanding_amount', 3000)
            ->assertJsonPath('data.claim.pending_payment.payment_status', 'pending')
            ->assertJsonStructure(['data' => ['claim' => ['payments', 'payment_entry', 'items', 'images']]]);

        $stranger = $this->createUser();
        $this->actingAs($stranger)->getJson($this->base()."/claims/{$return->return_number}")->assertNotFound();
        $this->actingAs($this->user)->getJson($this->base().'/claims/NOPE-0000')->assertNotFound();
    }

    /**
     * 상세가 대기 건을 낼 수 있는 수단마다 표시명을 함께 싣는다
     *
     * 값만 실으면 화면은 `point` 가 아닌 수단을 모두 「카드·간편결제」로 그린다 — 계좌이체를
     * 고르면서 카드로 낸다고 읽게 된다(S1-D14, 2026-10-07 실측).
     *
     * @return void 반환값 없음
     *
     * @effects claim_payment_method_choices_carry_their_own_labels
     */
    public function test_show_labels_every_available_payment_method(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.charge_payment_methods', ['card', 'bank']);
        app(EcommerceSettingsService::class)->clearCache();

        $order = $this->myDeliveredOrder();
        $result = app(UserClaimService::class)->requestReturn($order, ['items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'pay_on_request', 'requested_by' => $this->user->id], [], 'card');
        $return = $result['claim'];

        $response = $this->actingAs($this->user)->getJson($this->base()."/claims/{$return->return_number}")
            ->assertOk()
            ->assertJsonPath('data.claim.available_payment_methods', ['card', 'bank']);

        $labels = $response->json('data.claim.available_payment_method_labels');
        $this->assertIsArray($labels, '수단 표시명이 실리지 않는다');
        $this->assertSame(app(PaymentMethodResolver::class)->label('bank'), $labels['bank'] ?? null);
        $this->assertNotSame($labels['card'] ?? null, $labels['bank'] ?? null);
    }

    /**
     * 상세는 관리자 상세와 같은 진행단계 배열을 같은 자리(`data.progress_steps`)에 싣는다.
     *
     * 관리자 응답에만 있던 배열이라 회원 화면은 상태 뱃지 하나로만 단계를 읽어야 했다. 배열을
     * 화면이 다시 적으면 그 사본이 어휘의 두 번째 출처가 되므로 서버가 한 곳에서 만든다.
     * 칸마다 아이콘·색이 실리고 헤더의 `status_icon` 도 같은 Enum 값이다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, charge_method=pay_on_request, refund_branch=none
     *
     * @effects claim_detail_response_carries_progress_steps
     */
    public function test_show_carries_the_progress_steps_and_the_status_icon(): void
    {
        $order = $this->myDeliveredOrder();
        $result = app(UserClaimService::class)->requestReturn($order, ['items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'pay_on_request', 'requested_by' => $this->user->id], [], 'card');
        $return = $result['claim'];

        $response = $this->actingAs($this->user)->getJson($this->base()."/claims/{$return->return_number}")
            ->assertOk()
            ->assertJsonPath('data.claim.status', 'payment_pending')
            ->assertJsonPath('data.claim.status_icon', ReturnStatusEnum::PAYMENT_PENDING->icon());

        $steps = $response->json('data.progress_steps');

        $this->assertSame(
            array_column(ReturnStatusEnum::progressSequence(), 'value'),
            array_column($steps, 'value'),
            '결제형 청구(신청 시 결제)라 결제 두 칸을 포함한 전체 순서가 실려야 합니다.'
        );
        $this->assertSame('payment_pending', collect($steps)->firstWhere('state', 'current')['value']);

        foreach ($steps as $step) {
            $case = ReturnStatusEnum::from($step['value']);
            $this->assertSame($case->icon(), $step['icon']);
            $this->assertSame($case->variant(), $step['variant']);
        }
    }

    /**
     * 목록은 화면이 그 행에서 실제로 그리는 것만 싣는다. (S13 — P3)
     *
     * 이 리소스는 목록·상세 겸용이라 상세용 필드(첨부 전량·주소/배송비 스냅샷 JSON·상세 사유·
     * 반려 사유·환불 계좌)까지 행마다 실렸다. `per_page` 상한이 50 이고 신청서 하나가 상품
     * 200줄까지 가질 수 있어, 그대로 두면 한 페이지를 여는 것만으로 수천 줄이 응답에 실린다.
     *
     * 상품 행(`items`)은 남는다 — 목록 카드가 상품마다 썸네일·옵션·수량·금액을 그리므로
     * 그것은 낭비가 아니라 화면이 요구하는 값이다. 그 구분이 이 테스트의 요지다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, charge_method=pay_on_request, refund_branch=none
     *
     * @effects claim_list_response_drops_what_the_list_does_not_draw
     */
    public function test_index_omits_what_the_list_does_not_draw(): void
    {
        $order = $this->myDeliveredOrder();
        $claims = app(UserClaimService::class);
        $return = $claims->requestReturn($order, [
            'items' => $this->items($order),
            'reason_code' => 'changed_mind',
            'reason_detail' => '상세 사유가 여기에 있다',
            'charge_method' => 'deduct_from_refund',
            'requested_by' => $this->user->id,
            'refund_bank' => ['bank_code' => '004', 'account_number' => '110234567890', 'holder' => '홍길동'],
        ])['claim'];

        // 상세는 그 값을 전부 싣는다 — 목록만 좁힌다는 것이 이 테스트의 대조군이다.
        $this->actingAs($this->user)->getJson($this->base()."/claims/{$return->return_number}")
            ->assertOk()
            ->assertJsonPath('data.claim.reason_detail', '상세 사유가 여기에 있다')
            ->assertJsonPath('data.claim.refund_bank_code', '004');

        $response = $this->actingAs($this->user)->getJson($this->base().'/claims?type=return')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        $row = $response->json('data.data.0');

        // 화면이 그리는 것 — 남는다.
        $this->assertSame($return->return_number, $row['claim_number']);
        $this->assertNotEmpty($row['items']);
        $this->assertArrayHasKey('product_name', $row['items'][0]);
        $this->assertNotNull($row['reason_label']);
        $this->assertNotNull($row['status_label']);

        // 목록의 어느 칸도 읽지 않는 것 — 빠진다.
        $this->assertSame([], $row['images']);
        $this->assertNull($row['reason_detail']);
        $this->assertNull($row['reject_reason']);
        $this->assertNull($row['shipping_fee_snapshot']);
        $this->assertNull($row['return_address_snapshot']);
        $this->assertNull($row['refund_bank_code']);
        $this->assertNull($row['refund_bank_account']);
        $this->assertNull($row['refund_bank_holder']);
    }

    /**
     * 상세는 상품의 원 주문옵션과 배송사를 함께 싣는다.
     *
     * 번호 해석이 싣는 관계는 소유권 판정에 필요한 최소한이라(상품 행·첨부·주문) 그대로
     * 내보내면 상품명·옵션명·썸네일과 배송사명·추적 URL 이 **오류 없이 전부 null** 이 된다.
     * 응답은 200 이고 키도 그대로 있어 어긋난 흔적이 어디에도 남지 않는다 — S13 이 상세
     * 화면을 만들며 찾은 결함의 회귀 가드다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, charge_method=deduct_from_refund, refund_branch=none
     *
     * @effects claim_detail_loads_the_relations_the_screen_draws
     */
    public function test_show_loads_the_relations_the_detail_screen_draws(): void
    {
        $order = $this->myDeliveredOrder();
        $claims = app(UserClaimService::class);
        $return = $claims->requestReturn($order, [
            'items' => $this->items($order),
            'reason_code' => 'changed_mind',
            'charge_method' => 'deduct_from_refund',
            'requested_by' => $this->user->id,
        ])['claim'];

        $row = $this->actingAs($this->user)->getJson($this->base()."/claims/{$return->return_number}")
            ->assertOk()
            ->json('data.claim.items.0');

        // 상품명이 비면 상세 화면의 상품 카드가 통째로 빈 줄이 된다.
        $this->assertNotNull($row['product_name']);
        $this->assertNotSame('', $row['product_name']);
    }

    /**
     * 승인 전에는 사유·귀책·청구액이 다시 계산되고, 승인 뒤 수정은 422
     *
     * @return void 반환값 없음
     *
     * @effects claim_update_changes_the_reason_before_approval_only
     */
    public function test_update_changes_the_reason_before_approval_and_is_422_after(): void
    {
        $order = $this->myDeliveredOrder();
        $claims = app(UserClaimService::class);
        $return = $claims->requestReturn($order, ['items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'requested_by' => $this->user->id])['claim'];

        $this->actingAs($this->user)->patchJson($this->base()."/claims/{$return->return_number}", [
            'reason_code' => 'defective_product', 'reason_detail' => '불량',
        ])->assertOk()
            ->assertJsonPath('data.claim.reason_code', 'defective_product')
            ->assertJsonPath('data.claim.fault_type', 'seller')
            ->assertJsonPath('data.claim.total_charge_amount', 0);

        $return->fresh()->forceFill(['return_status' => ReturnStatusEnum::APPROVED])->save();

        $this->actingAs($this->user)->patchJson($this->base()."/claims/{$return->return_number}", ['reason_detail' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_not_editable'));
    }

    /**
     * 수정 저장이 구매자가 새로 적은 환불 계좌를 신청서에 반영한다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=request
     *
     * @effects claim_edit_saves_the_refund_account
     */
    public function test_update_saves_the_refund_account_the_buyer_typed(): void
    {
        // 2026-09-01 확정 — 환불 계좌는 수정 화면에서 고칠 수 있다(신청 단계에서만).
        // 화면이 칸을 열어 두는데 저장 경로가 그 값을 받지 않으면, 구매자는 채워 넣고 저장
        // 성공을 본 뒤에도 옛 계좌로 돈을 받는다. 응답은 200 이라 어긋난 흔적이 남지 않는다.
        $order = $this->myDeliveredOrder();
        $claims = app(UserClaimService::class);
        $return = $claims->requestReturn($order, [
            'items' => $this->items($order),
            'reason_code' => 'changed_mind',
            'charge_method' => 'deduct_from_refund',
            'requested_by' => $this->user->id,
            'refund_bank' => ['bank_code' => '004', 'account_number' => '11100022233', 'holder' => '홍길동'],
        ])['claim'];

        $this->actingAs($this->user)->patchJson($this->base()."/claims/{$return->return_number}", [
            'reason_detail' => '계좌를 바꿉니다',
            'refund_bank' => ['bank_code' => '088', 'account_number' => '99988877766', 'holder' => '김철수'],
        ])->assertOk();

        $fresh = $return->fresh();

        $this->assertSame('088', $fresh->refund_bank_code);
        $this->assertSame('99988877766', $fresh->refund_bank_account);
        $this->assertSame('김철수', $fresh->refund_bank_holder);
    }

    /**
     * 계좌 칸을 비운 채 저장하면 이미 기록된 계좌를 그대로 둔다.
     *
     * 수정 화면은 마스킹 때문에 칸을 빈 채로 연다 — 그 빈 값을 반영하면 사유만 고친 저장
     * 한 번이 계좌를 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=request
     *
     * @effects claim_edit_keeps_the_recorded_refund_account
     */
    public function test_update_without_a_refund_account_keeps_the_recorded_one(): void
    {
        // 표시 응답의 계좌는 마스킹된 값이라 화면이 되돌려 보낼 수 없다 — 그래서 수정 화면은
        // 칸을 빈 채로 연다. 그 빈 값을 저장이 반영하면, 사유만 고친 저장 한 번이 계좌를
        // 지운다.
        $order = $this->myDeliveredOrder();
        $claims = app(UserClaimService::class);
        $return = $claims->requestReturn($order, [
            'items' => $this->items($order),
            'reason_code' => 'changed_mind',
            'charge_method' => 'deduct_from_refund',
            'requested_by' => $this->user->id,
            'refund_bank' => ['bank_code' => '004', 'account_number' => '11100022233', 'holder' => '홍길동'],
        ])['claim'];

        $this->actingAs($this->user)->patchJson($this->base()."/claims/{$return->return_number}", [
            'reason_detail' => '사유만 고칩니다',
        ])->assertOk();

        $fresh = $return->fresh();

        $this->assertSame('004', $fresh->refund_bank_code);
        $this->assertSame('11100022233', $fresh->refund_bank_account);
        $this->assertSame('홍길동', $fresh->refund_bank_holder);
    }

    /**
     * 세 칸 중 일부만 채운 계좌는 저장이 거절하고 옛 값을 건드리지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=request
     *
     * @effects claim_edit_rejects_a_partly_filled_refund_account
     */
    public function test_update_rejects_a_partly_filled_refund_account(): void
    {
        // 셋 중 하나만 오면 은행만 바뀌고 계좌번호는 옛 값으로 남아, 운영자가 존재하지 않는
        // 계좌로 이체를 시도한다. 접수 경로와 같은 등식이어야 한다.
        $order = $this->myDeliveredOrder();
        $claims = app(UserClaimService::class);
        $return = $claims->requestReturn($order, [
            'items' => $this->items($order),
            'reason_code' => 'changed_mind',
            'charge_method' => 'deduct_from_refund',
            'requested_by' => $this->user->id,
            'refund_bank' => ['bank_code' => '004', 'account_number' => '11100022233', 'holder' => '홍길동'],
        ])['claim'];

        $this->actingAs($this->user)->patchJson($this->base()."/claims/{$return->return_number}", [
            'refund_bank' => ['account_number' => '99988877766'],
        ])->assertStatus(422);

        // 거절된 요청이 상태를 남기면 안 된다.
        $this->assertSame('11100022233', $return->fresh()->refund_bank_account);
    }

    /**
     * 구매자에게 보이지 않는 사유는 수정으로도 고를 수 없다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_update_rejects_reasons_hidden_from_users
     */
    public function test_update_cannot_pick_a_reason_that_is_hidden_from_users(): void
    {
        // 화면에 뜨지도 않는 판매자 귀책 사유를 수정으로 지정하면 회수 배송비를 0 으로 만들 수 있다.
        // 수정 요청도 이제 신청서 유형을 해석해 접수와 같은 카탈로그 게이트(`is_user_selectable=true`)를
        // 걸므로 검증 단계가 먼저 거절한다(#599 validate P3). 서비스의 같은 가드는 요청을 거치지 않는
        // 호출을 위해 그대로 남는다.
        $order = $this->myDeliveredOrder();
        $claims = app(UserClaimService::class);
        $return = $claims->requestReturn($order, [
            'items' => $this->items($order),
            'reason_code' => 'changed_mind',
            'charge_method' => 'deduct_from_refund',
            'requested_by' => $this->user->id,
        ])['claim'];

        $hidden = ClaimReason::create([
            'type' => 'return',
            'code' => 'seller_only_reason',
            'name' => ['ko' => '판매자 귀책 전용', 'en' => 'Seller only'],
            'fault_type' => 'seller',
            'is_user_selectable' => false,
            'is_active' => true,
            'sort_order' => 99,
        ]);

        $before = $return->fresh();

        $this->actingAs($this->user)->patchJson($this->base()."/claims/{$return->return_number}", [
            'reason_code' => $hidden->code,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('reason_code')
            ->assertJsonPath('message', __('sirsoft-ecommerce::validation.claims.reason_code.exists'));

        $fresh = $return->fresh();
        $this->assertSame($before->reason_code, $fresh->reason_code);
        $this->assertSame($before->fault_type, $fresh->fault_type);
        $this->assertEqualsWithDelta((float) $before->claim_shipping_fee, (float) $fresh->claim_shipping_fee, 0.001);
    }

    /**
     * 첨부 상한 초과는 신청서를 **고치기 전에** 거절한다 — 반쯤 바뀐 상태를 남기지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_update_validates_attachments_before_mutating_anything
     */
    public function test_update_rejects_over_limit_attachments_before_it_changes_anything(): void
    {
        // 첨부 자격을 수정 본문을 쓴 **뒤**에 보면, 사유 변경은 커밋되고 삭제 대상 첨부는 파일까지
        // 지워진 상태에서 422 가 나간다. 사용자는 "수정 실패" 를 보지만 신청서는 이미 바뀌어 있다.
        // 상한 판정은 `attachPending()` 과 같은 등식(삭제 후 남을 장수 + 신규)이어야 한다.
        $order = $this->myDeliveredOrder();
        $attachments = app(ClaimAttachmentService::class);
        $claims = app(UserClaimService::class);

        $existing = collect(range(1, 3))->map(
            fn (int $i) => $attachments->uploadPending(UploadedFile::fake()->image("old{$i}.jpg"), $order, $this->user->id)
        );
        $return = $claims->requestReturn(
            $order,
            ['items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'requested_by' => $this->user->id],
            $existing->pluck('id')->map('intval')->all(),
        )['claim'];

        $added = collect(range(1, 4))->map(
            fn (int $i) => $attachments->uploadPending(UploadedFile::fake()->image("new{$i}.jpg"), $order, $this->user->id)
        );

        // 남을 3장 + 신규 4장 = 7 > 상한 5. 삭제 1장을 함께 요청해도 6 이라 여전히 초과다.
        $this->actingAs($this->user)->patchJson($this->base()."/claims/{$return->return_number}", [
            'reason_code' => 'defective_product',
            'reason_detail' => '불량',
            'add_image_ids' => $added->pluck('id')->map('intval')->all(),
            'remove_image_ids' => [(int) $existing->first()->id],
        ])->assertStatus(422)
            ->assertJsonPath('message', trans_count('sirsoft-ecommerce::exceptions.claim_image_limit_exceeded', ['max' => 5, 'count' => 5]));

        $fresh = $return->fresh();
        $this->assertSame('changed_mind', $fresh->reason_code, '거절된 수정이 사유를 바꿔 놓으면 안 됩니다.');
        $this->assertSame(
            3,
            ClaimImage::where('claimable_type', 'return')->where('claimable_id', $return->id)->count(),
            '거절된 수정이 첨부를 지우면 사용자는 스스로 되돌릴 수 없습니다.',
        );
    }

    /**
     * 신청서에 붙은 첨부는 미귀속 삭제 경로(DELETE)로 지울 수 없다.
     *
     * DELETE 는 작성 화면에서 **아직 신청서에 붙지 않은** 사진을 치우는 경로다. 소유 판정만 보면
     * 접수가 끝난(승인·완료된) 신청서의 증빙도 이 경로로 지워져, 수정 창(승인 전만 허용)을
     * 통째로 우회한다 — 분쟁 때 남아 있어야 할 사진이 고객 손에 사라진다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, screen=edit, entry=detail
     *
     * @effects attached_claim_image_is_not_deletable_through_the_pending_route
     */
    public function test_an_attached_image_cannot_be_deleted_through_the_pending_route(): void
    {
        $order = $this->myDeliveredOrder();
        $image = app(ClaimAttachmentService::class)->uploadPending(UploadedFile::fake()->image('proof.jpg'), $order, $this->user->id);
        $return = app(UserClaimService::class)->requestReturn(
            $order,
            ['items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'requested_by' => $this->user->id],
            [(int) $image->id],
        )['claim'];

        $this->actingAs($this->user)->deleteJson($this->base()."/claims/images/{$image->id}")->assertNotFound();

        $this->assertNotNull(ClaimImage::find($image->id), '신청서에 붙은 증빙이 미귀속 삭제 경로로 지워지면 안 됩니다.');
        $this->assertSame('return', ClaimImage::find($image->id)->claimable_type);
        $this->assertSame($return->id, (int) ClaimImage::find($image->id)->claimable_id);
    }

    /**
     * 수정 화면은 운영자가 올린 첨부를 지울 수 없다 — 아무것도 바꾸기 전에 거절한다.
     *
     * 운영자가 상세에서 대리로 올린 사진은 "누가 낸 증거인가" 를 가르는 기록이다. 고객이 자기
     * 수정 화면에서 그 사진을 `remove_image_ids` 에 실어 보내면 지금은 그대로 지워진다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, screen=edit, entry=detail
     *
     * @effects buyer_edit_cannot_remove_an_operator_uploaded_image
     */
    public function test_update_cannot_remove_an_image_the_operator_uploaded(): void
    {
        $order = $this->myDeliveredOrder();
        $return = app(UserClaimService::class)->requestReturn(
            $order,
            ['items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'requested_by' => $this->user->id],
        )['claim'];

        $adminImage = ClaimImage::factory()->create([
            'claimable_type' => ClaimTypeEnum::RETURN->value,
            'claimable_id' => $return->id,
            'uploader_type' => 'admin',
        ]);

        $this->actingAs($this->user)->patchJson($this->base()."/claims/{$return->return_number}", [
            'reason_code' => 'defective_product',
            'reason_detail' => '불량',
            'remove_image_ids' => [(int) $adminImage->id],
        ])->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_image_not_removable'));

        $this->assertNotNull(ClaimImage::find($adminImage->id), '운영자 증빙이 고객 수정으로 지워지면 안 됩니다.');
        $this->assertSame('changed_mind', $return->fresh()->reason_code, '거절된 수정이 사유를 바꿔 놓으면 안 됩니다.');
    }

    /**
     * 고객이 올린 첨부는 수정 화면에서 계속 지울 수 있다(비회귀).
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, screen=edit, entry=detail
     *
     * @effects buyer_edit_still_removes_its_own_image
     */
    public function test_update_still_removes_an_image_the_buyer_uploaded(): void
    {
        $order = $this->myDeliveredOrder();
        $image = app(ClaimAttachmentService::class)->uploadPending(UploadedFile::fake()->image('mine.jpg'), $order, $this->user->id);
        $return = app(UserClaimService::class)->requestReturn(
            $order,
            ['items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'requested_by' => $this->user->id],
            [(int) $image->id],
        )['claim'];

        $this->actingAs($this->user)->patchJson($this->base()."/claims/{$return->return_number}", [
            'reason_code' => 'changed_mind',
            'remove_image_ids' => [(int) $image->id],
        ])->assertOk();

        $this->assertNull(ClaimImage::find($image->id));
    }

    /**
     * 철회가 신청서를 닫고 결제된 마일리지를 환불하며, 두 번째 철회는 422
     *
     * @return void 반환값 없음
     *
     * @effects claim_withdraw_closes_and_refunds_the_paid_charge
     */
    public function test_withdraw_closes_the_claim_and_refunds_the_mileage_payment(): void
    {
        $order = $this->myDeliveredOrder();
        $this->grantMileage($this->user->id, 10000);
        $claims = app(UserClaimService::class);
        $result = $claims->requestReturn($order, ['items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'pay_on_request', 'requested_by' => $this->user->id], [], 'point');
        $this->assertSame('paid', $result['payment']->payment_status->value);

        $this->actingAs($this->user)->postJson($this->base()."/claims/{$result['claim']->return_number}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.claim.status', 'withdrawn')
            ->assertJsonPath('data.claim.payments.0.payment_status', 'refunded');

        $this->actingAs($this->user)->postJson($this->base()."/claims/{$result['claim']->return_number}/withdraw")
            ->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_not_withdrawable'));
    }

    // ================================================================
    // 첨부 / 추가결제
    // ================================================================

    /**
     * 첨부 업로드·삭제 왕복 — 이미지 아닌 파일은 422, 남의 첨부 삭제는 404
     *
     * @return void 반환값 없음
     *
     * @effects claim_image_upload_delete_round_trip_with_type_gate, claim_endpoints_scope_to_the_requesting_member
     */
    public function test_image_upload_and_delete_round_trip(): void
    {
        $order = $this->myDeliveredOrder();

        $upload = $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/claims/images", [
            'image' => UploadedFile::fake()->image('claim.jpg', 800, 600),
        ]);

        $upload->assertCreated()->assertJsonPath('data.claimable_type', ClaimImage::PENDING_CLAIMABLE_TYPE);
        $imageId = $upload->json('data.id');

        $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/claims/images", [
            'image' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ])->assertStatus(422);

        $stranger = $this->createUser();
        $this->actingAs($stranger)->deleteJson($this->base()."/claims/images/{$imageId}")->assertNotFound();
        $this->actingAs($this->user)->deleteJson($this->base()."/claims/images/{$imageId}")->assertOk();
        $this->assertNull(ClaimImage::find($imageId));
    }

    /**
     * PG 승인 콜백과 마일리지 결제가 결제행을 paid 로 만들고, 남의 결제 건은 404
     *
     * @return void 반환값 없음
     *
     * @effects claim_payment_pay_and_pg_approve_round_trip, claim_endpoints_scope_to_the_requesting_member
     */
    public function test_pay_with_mileage_and_approve_with_the_pg_hook(): void
    {
        $this->registerTestPgProvider();
        $this->fakeClaimPaymentApprove(true);
        $this->grantMileage($this->user->id, 10000);
        $order = $this->myDeliveredOrder();
        $claims = app(UserClaimService::class);
        $result = $claims->requestReturn($order, ['items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'pay_on_request', 'requested_by' => $this->user->id], [], 'card');
        $number = $result['payment']->payment_number;

        // PG 승인 콜백
        $this->actingAs($this->user)->postJson($this->base()."/claim-payments/{$number}/approve", ['callback' => ['paymentKey' => 'pk']])
            ->assertOk()->assertJsonPath('data.payment.payment_status', 'paid');

        // 다른 회원의 결제 건은 보이지 않는다.
        $this->actingAs($this->createUser())->postJson($this->base()."/claim-payments/{$number}/pay", [])->assertNotFound();

        // 마일리지 결제 — 새 반품
        $order2 = $this->myDeliveredOrder();
        $second = $claims->requestReturn($order2, ['items' => $this->items($order2), 'reason_code' => 'changed_mind', 'charge_method' => 'pay_on_request', 'requested_by' => $this->user->id], [], 'card');
        $this->actingAs($this->user)->postJson($this->base()."/claim-payments/{$second['payment']->payment_number}/pay", ['payment_method' => 'point'])
            ->assertOk()
            ->assertJsonPath('data.payment.payment_status', 'paid')
            ->assertJsonPath('data.requires_pg_payment', false);
    }

    /**
     * 서명된 첨부 주소는 세션 없이도 파일을 내주고, 서명 없는 해시는 열람 권한을 요구한다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_attachment_signed_url_serves_without_session
     */
    public function test_signed_attachment_url_serves_the_file_and_the_bare_hash_needs_a_viewer(): void
    {
        // 서빙 라우트 자체를 때리는 테스트가 없어, 라우트를 지워도 스위트가 green 이었다
        // (기존 단언은 `download_url` 의 **문자열 접두사**만 봤다).
        $storage = Mockery::mock(StorageInterface::class);
        $storage->allows('put')->andReturn(true);
        $storage->allows('delete')->andReturn(true);
        $storage->allows('exists')->andReturn(true);
        $storage->allows('getDisk')->andReturn('local');
        $storage->allows('response')->andReturn(new StreamedResponse(fn () => null));
        $this->app->when(ClaimAttachmentService::class)->needs(StorageInterface::class)->give(fn () => $storage);

        $order = $this->myDeliveredOrder();
        $signed = $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/claims/images", [
            'image' => UploadedFile::fake()->image('claim.jpg'),
        ])->assertCreated()->json('data.download_url');

        $bare = explode('?', $signed)[0];

        // `actingAs` 는 같은 테스트의 이후 요청에도 이어진다 — 명시적으로 끊지 않으면
        // '비인증' 단언이 실제로는 소유자 인증 상태를 재는 셈이 된다.
        $this->app['auth']->forgetGuards();

        // 한시 서명은 세션 없이 통과한다 — 비회원 신청자가 첨부를 여는 유일한 경로다.
        $this->get($signed)->assertOk();

        // 서명이 없으면 열람자 게이트로 떨어진다. 비로그인은 404(존재 비노출), 본인은 통과.
        $this->get($bare)->assertNotFound();
        $this->actingAs($this->user)->get($bare)->assertOk();
    }

    /**
     * 주문 상세가 클레임 추가결제 합계를 금액·표기 두 키로 낸다
     *
     * @return void 반환값 없음
     *
     * @effects order_detail_exposes_claim_additional_paid_total
     */
    public function test_order_detail_exposes_the_claim_additional_paid_total(): void
    {
        $this->grantMileage($this->user->id, 10000);
        $order = $this->myDeliveredOrder();
        app(UserClaimService::class)->requestReturn($order, ['items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'pay_on_request', 'requested_by' => $this->user->id], [], 'point');

        $this->actingAs($this->user)->getJson($this->base()."/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.claim_additional_paid_total', 3000)
            ->assertJsonPath('data.claim_additional_paid_total_formatted', '₩3,000');
    }

    /**
     * 클레임 결제 금액은 응답 어느 자리에서든 주문 시점 통화 스냅샷의 소수 자릿수를 따른다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_payment_amounts_follow_the_order_currency_snapshot
     */
    public function test_claim_payment_amounts_use_the_order_currency_snapshot_in_every_response_slot(): void
    {
        // 이 주문은 KRW 를 소수 2자리로 쓰던 시절에 결제됐고, 현재 설정은 0자리다.
        // 과거 주문의 표기는 스냅샷이 SSoT 이므로 응답 어느 자리든 2자리로 나와야 한다.
        $order = $this->myDeliveredOrder();
        $snapshot = $this->krwCurrencySnapshot();
        $snapshot['exchange_rates']['KRW']['decimal_places'] = 2;
        $order->forceFill(['currency_snapshot' => $snapshot])->save();
        $order = $order->fresh(['options', 'shippingAddress']);
        $claims = app(UserClaimService::class);

        // 판매자 귀책으로 접수하면 청구액이 0 이라 결제 건이 없다.
        $return = $claims->requestReturn($order, [
            'items' => $this->items($order),
            'reason_code' => 'defective_product',
            'charge_method' => 'pay_on_request',
            'requested_by' => $this->user->id,
        ])['claim'];

        // 고객 귀책으로 바꾸면 반품 배송비가 청구되어 결제 대기 건이 생기고, 같은 결제 행이
        // 한 응답 안 세 자리(claim.payments[] · claim.pending_payment · 최상위 payment)에 실린다.
        $response = $this->actingAs($this->user)
            ->patchJson($this->base()."/claims/{$return->return_number}", [
                'reason_code' => 'changed_mind',
                'charge_method' => 'pay_on_request',
            ])
            ->assertOk();

        $listed = $response->json('data.claim.payments.0.amount_formatted');
        $pending = $response->json('data.claim.pending_payment.amount_formatted');
        $topLevel = $response->json('data.payment.amount_formatted');

        $this->assertNotNull($topLevel, '수정으로 새 결제 건이 생기면 최상위 payment 가 실려야 한다');
        $this->assertSame('₩3,000.00', $listed);
        $this->assertSame($listed, $pending);
        // 같은 결제 행이 실리는 자리마다 표기가 갈리면 운영자가 통화 설정을 바꾼 뒤
        // 한 화면 안에서 근거가 둘로 나뉜다.
        $this->assertSame($listed, $topLevel);
    }

    // ================================================================
    // 예외 → 상태코드 페어
    // ================================================================

    /**
     * 도메인 예외는 422 + 그 사유 문구
     *
     * @return void 반환값 없음
     *
     * @effects claim_domain_exception_is_422_infrastructure_is_500
     */
    public function test_domain_exception_is_422(): void
    {
        $order = $this->myDeliveredOrder();

        $this->mock(UserClaimService::class, function ($mock) {
            $mock->shouldReceive('requestReturn')->andThrow(OrderClaimException::reasonUnavailable());
        });
        $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/returns", [
            'items' => $this->items($order), 'reason_code' => 'changed_mind',
        ])->assertStatus(422)->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_reason_unavailable'));
    }

    // 라우터가 컨트롤러 인스턴스를 Route 객체에 캐시하므로, 한 테스트 안에서 두 번째 mock 은 첫 요청의 컨트롤러에 닿지 않는다 — 메서드를 나눈다.
    /**
     * 인프라 예외는 500 이며 드라이버 메시지를 응답에 흘리지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_domain_exception_is_422_infrastructure_is_500
     */
    public function test_infrastructure_exception_is_500_without_leaking_the_message(): void
    {
        $order = $this->myDeliveredOrder();

        $this->mock(UserClaimService::class, function ($mock) {
            $mock->shouldReceive('requestReturn')->andThrow(new \RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));
        });
        $response = $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/returns", [
            'items' => $this->items($order), 'reason_code' => 'changed_mind',
        ]);
        $this->assertSame(500, $response->status(), $response->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    /**
     * 비인증 요청은 401
     *
     * @return void 반환값 없음
     *
     * @effects claim_endpoints_require_authentication
     */
    public function test_unauthenticated_requests_are_rejected(): void
    {
        $order = $this->myDeliveredOrder();

        $this->getJson($this->base()."/orders/{$order->id}/claimable-options")->assertUnauthorized();
        $this->getJson($this->base().'/claims')->assertUnauthorized();
    }

    // ================================================================
    // 인가 — 권한 4종
    // ================================================================

    /**
     * `user-claims.*` 4종 중 하나만 빠진 역할의 회원을 만듭니다.
     *
     * 라우트 권한 미들웨어는 컨트롤러·모델 해석보다 먼저 돌기 때문에, 대상이 존재하지 않아도
     * 403 이 먼저 나온다. 그래서 이 검사는 "미들웨어가 그 라우트에 붙어 있는가" 를 직접 묻는다 —
     * 미들웨어가 빠지면 같은 요청이 404 로 떨어져 red 가 된다.
     *
     * @param  string  $withheld  보류할 권한 식별자 접미사 (read/request/withdraw/pay)
     * @return User 그 권한만 없는 회원
     */
    protected function userWithoutClaimPermission(string $withheld): User
    {
        $role = Role::create([
            'identifier' => 'test_claims_without_'.$withheld,
            'name' => ['ko' => '클레임 권한 일부 보류', 'en' => 'Partial Claim Role'],
            'is_active' => true,
        ]);

        foreach (['read', 'request', 'withdraw', 'pay'] as $ability) {
            if ($ability === $withheld) {
                continue;
            }

            $permission = Permission::firstOrCreate(
                ['identifier' => 'sirsoft-ecommerce.user-claims.'.$ability],
                [
                    'name' => ['ko' => '클레임 '.$ability, 'en' => 'Claim '.$ability],
                    'type' => PermissionType::User,
                ]
            );

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * user-claims.read 가 없으면 목록이 403
     *
     * @return void 반환값 없음
     *
     * @effects claim_routes_carry_their_user_claims_permission
     */
    public function test_claim_read_requires_the_read_permission(): void
    {
        $this->actingAs($this->userWithoutClaimPermission('read'))
            ->getJson($this->base().'/claims')
            ->assertForbidden();
    }

    /**
     * user-claims.request 가 없으면 미리보기가 403
     *
     * @return void 반환값 없음
     *
     * @effects claim_routes_carry_their_user_claims_permission
     */
    public function test_claim_request_requires_the_request_permission(): void
    {
        $order = $this->myDeliveredOrder();

        $this->actingAs($this->userWithoutClaimPermission('request'))
            ->postJson($this->base()."/orders/{$order->id}/claims/preview", [
                'type' => 'return',
                'items' => $this->items($order),
                'reason_code' => 'changed_mind',
            ])
            ->assertForbidden();
    }

    /**
     * user-claims.withdraw 가 없으면 403 이고 철회가 반영되지 않는다
     *
     * @return void 반환값 없음
     *
     * @effects claim_routes_carry_their_user_claims_permission, claim_permission_denial_leaves_no_side_effect
     */
    public function test_claim_withdraw_requires_the_withdraw_permission(): void
    {
        $order = $this->myDeliveredOrder();
        $return = app(UserClaimService::class)->requestReturn($order, [
            'items' => $this->items($order),
            'reason_code' => 'changed_mind',
            'charge_method' => 'deduct_from_refund',
            'requested_by' => $this->user->id,
        ])['claim'];

        $this->actingAs($this->userWithoutClaimPermission('withdraw'))
            ->postJson($this->base()."/claims/{$return->return_number}/withdraw")
            ->assertForbidden();

        // 403 은 상태코드만으로 충분하지 않다 — 가드가 쓰기 뒤에 있으면 거절하면서도 철회가 반영된다.
        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh()->return_status);
        $this->assertNull($return->fresh()->withdrawn_at);
    }

    /**
     * user-claims.pay 가 없으면 403 이고 결제행·마일리지 원장이 그대로다
     *
     * @return void 반환값 없음
     *
     * @effects claim_routes_carry_their_user_claims_permission, claim_permission_denial_leaves_no_side_effect
     */
    public function test_claim_payment_requires_the_pay_permission(): void
    {
        $order = $this->myDeliveredOrder();
        $result = app(UserClaimService::class)->requestReturn($order, [
            'items' => $this->items($order),
            'reason_code' => 'changed_mind',
            'charge_method' => 'pay_on_request',
            'requested_by' => $this->user->id,
        ], [], 'card');
        $payment = $result['payment'];

        // 수단을 바꾸는 요청으로 보낸다 — `card` 로 보내면 서비스의 수단 변경 분기 자체가 거짓이라
        // 가드를 통째로 되돌려도 아래 단언이 전부 통과한다(고정처럼 보이는 미고정).
        $before = $payment->fresh();

        $this->actingAs($this->userWithoutClaimPermission('pay'))
            ->postJson($this->base()."/claim-payments/{$payment->payment_number}/pay", ['payment_method' => 'point'])
            ->assertForbidden();

        // 거절된 결제 요청은 결제행도 마일리지 원장도 건드리지 않아야 한다.
        $fresh = $payment->fresh();
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $fresh->payment_status);
        $this->assertNull($fresh->paid_at);
        // 요청 전 값과의 비교여야 한다 — 고정 상수와 비교하면 픽스처가 우연히 그 값일 때 미고정이 된다.
        $this->assertSame($before->payment_method, $fresh->payment_method);
        $this->assertSame($before->pg_provider, $fresh->pg_provider);
        $this->assertEqualsWithDelta((float) $before->amount, (float) $fresh->amount, 0.001);
        $this->assertEqualsWithDelta((float) $before->points_amount, (float) $fresh->points_amount, 0.001);
        $this->assertSame(0, MileageTransaction::where('claim_payment_id', $payment->id)->count());
    }

    /**
     * 목록의 상태 필터는 요청한 유형의 어휘 안에서만 통과한다.
     *
     * @return void 반환값 없음
     *
     * @effects user_claim_index_status_filter_gated_by_type_vocabulary
     */
    public function test_index_status_filter_is_gated_by_the_vocabulary_of_the_requested_type(): void
    {
        // 상태 어휘는 유형마다 다르다 — 게이트가 없으면 다른 유형의 상태값이 그대로 저장소 where 로 흘러
        // 422 없이 빈 목록이 되고, 사용자에게는 "해당 신청 없음" 과 구분되지 않는다.
        $this->actingAs($this->user)->getJson($this->base().'/claims?type=exchange&status=redelivering')->assertOk();

        // `redelivering` 은 교환에만 있다 (반품 11종에 없음).
        $this->actingAs($this->user)->getJson($this->base().'/claims?type=return&status=redelivering')
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', __('sirsoft-ecommerce::validation.claims.status.in'));

        // `processing` 은 환불에만 있다 (교환 14종에 없음).
        $this->actingAs($this->user)->getJson($this->base().'/claims?type=exchange&status=processing')->assertStatus(422);
        $this->actingAs($this->user)->getJson($this->base().'/claims?type=refund&status=processing')->assertOk();

        // 유형을 생략하면 기본 유형(교환)의 어휘로 판정한다 — `claimType()` 기본값과 같은 축이다.
        $this->actingAs($this->user)->getJson($this->base().'/claims?status=redelivering')->assertOk();
        $this->actingAs($this->user)->getJson($this->base().'/claims?status=processing')->assertStatus(422);
    }

    /**
     * 기간 필터의 종료일은 시각이 붙어 있어도 그날 전체를 덮는다.
     *
     * @return void 반환값 없음
     *
     * @effects user_claim_index_end_date_covers_the_whole_day
     */
    public function test_index_end_date_covers_the_whole_day_even_when_a_time_is_given(): void
    {
        // 기간 두 축은 이 모듈의 목록 요청 관례(`date`)를 따르고, 하루 경계 해석은 저장소가 맡는다.
        // 종전에는 요청이 `to` 에 ' 23:59:59' 를 이어 붙여 시각이 섞인 값에서 규격 밖 문자열이 됐고,
        // 그것을 막으려 `to` 에만 형식을 고정하니 무가공으로 SQL 에 흘러드는 `from` 축은 그대로 남았다.
        $order = $this->myDeliveredOrder();
        app(UserClaimService::class)->requestReturn($order, [
            'items' => $this->items($order, 1), 'reason_code' => 'changed_mind',
            'charge_method' => 'deduct_from_refund', 'requested_by' => $this->user->id,
        ]);
        // 날짜 칸은 보는 사람(회원)의 시간대 하루로 해석된다 — 회원 시간대의 오늘로 보낸다.
        $today = now($this->user->timezone ?: config('app.default_user_timezone'))->toDateString();

        // 시각이 붙어 있어도 그날 전체를 덮는다 — 오늘 접수한 신청서가 결과에 남아야 한다.
        $this->actingAs($this->user)->getJson($this->base()."/claims?type=return&to={$today}%2000%3A00%3A00")
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        $this->actingAs($this->user)->getJson($this->base()."/claims?type=return&to={$today}")
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        // 같은 날 조회 (두 축 모두 날짜만) — 종전에는 `to` 형식 고정 때문에 시각이 붙은 `from` 과
        // 조합할 수 없었다. `after_or_equal` 은 여전히 원값을 비교하므로 `from` 에 시각을 넣으면
        // 그날 00:00 로 읽히는 `to` 보다 뒤가 되어 422 다 — 그 축은 이 라운드의 범위 밖이다.
        $this->actingAs($this->user)->getJson($this->base()."/claims?type=return&from={$today}&to={$today}")
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        // 해석 불가한 값은 여전히 거부한다 — 오타가 "필터 없음" 으로 조용히 통과하면 안 된다.
        $this->actingAs($this->user)->getJson($this->base().'/claims?type=return&to=not-a-date')->assertStatus(422);
    }

    /**
     * 없는 결제번호는 신청서 부재와 구분되는 결제 전용 문구의 404 다.
     *
     * @return void 반환값 없음
     *
     * @effects missing_claim_payment_is_404_with_its_own_message
     */
    public function test_missing_payment_is_404_with_the_payment_specific_message(): void
    {
        // 결제 부재는 신청서 부재와 다른 안내다 — 두 경로가 같은 문구를 쓰면 사용자는 무엇이 없는지 모른다.
        // 기대 문구는 요청 뒤에 해석한다 — 요청 처리 중 로케일이 정해지므로 먼저 읽으면 다른 언어와 비교하게 된다.
        $this->actingAs($this->user)->postJson($this->base().'/claim-payments/CP-NOPE/pay', ['payment_method' => 'card'])
            ->assertNotFound()
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_payment_not_found'));

        $this->actingAs($this->user)->postJson($this->base().'/claim-payments/CP-NOPE/approve', ['callback' => ['x' => 1]])
            ->assertNotFound()
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_payment_not_found'));

        $this->assertNotSame(
            __('sirsoft-ecommerce::exceptions.claim_payment_not_found'),
            __('sirsoft-ecommerce::exceptions.claim_not_found'),
        );
    }

    // ================================================================
    // 취소 미리보기 비대칭 해소 (S3 2차 확정 ② — 회원 면)
    // ================================================================

    /**
     * 클레임 코스에 잠긴 상품은 회원 취소 미리보기도 422 다.
     *
     * `previewRefund()` 의 클레임 가드가 기본 적용되면서 회원 면에도 새 422 경로가 생겼다.
     * 관리자 면만 단언하면 이 컨트롤러의 매핑(`OrderCancellationException` → 422)이
     * 끊겨도 회귀가 초록으로 남는다 — 세 면이 각각 다른 메시지 키를 쓰기 때문에 더 그렇다.
     *
     * @return void 반환값 없음
     *
     * @effects user_estimate_refund_is_422_for_claim_course_items
     */
    public function test_estimate_refund_is_422_for_items_locked_by_a_claim_course(): void
    {
        $order = $this->myDeliveredOrder();

        $this->actingAs($this->user)
            ->postJson($this->base()."/orders/{$order->id}/returns", [
                'items' => $this->items($order),
                'reason_code' => 'changed_mind',
                'charge_method' => 'deduct_from_refund',
            ])
            ->assertStatus(201);

        // 반품 접수로 코스 상태가 된 상품 — 미리보기도 실행과 같은 가드를 통과해야 한다.
        $response = $this->actingAs($this->user)
            ->postJson($this->base()."/orders/{$order->id}/estimate-refund", [
                'items' => [['order_option_id' => $order->options->first()->id, 'cancel_quantity' => 1]],
            ]);

        $response->assertStatus(422);

        // 사유가 뭉뚱그려지지 않는다 — 고객은 "무엇 때문에 막혔는지" 를 안내받아야 한다.
        $this->assertSame(
            __('sirsoft-ecommerce::exceptions.claim_option_in_course', [
                'status' => OrderStatusEnum::RETURN_IN_PROGRESS->label(),
            ]),
            $response->json('message'),
        );
    }

    /**
     * 목록 pagination 이 7키 계약을 지킨다 (기존 4키 + from/to/has_more_pages)
     *
     * @return void 반환값 없음
     *
     * @effects user_claim_pagination_meta_contract
     */
    public function test_claim_list_pagination_carries_the_pagination_meta_contract(): void
    {
        $order = $this->myDeliveredOrder();

        app(UserClaimService::class)->requestReturn($order, [
            'items' => $this->items($order),
            'reason_code' => 'changed_mind',
            'charge_method' => 'deduct_from_refund',
            'requested_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->getJson($this->base().'/claims?type=return');

        $response->assertOk()
            // 기존 4키는 그대로 유지된다 (계약 축소 금지).
            ->assertJsonStructure(['data' => ['pagination' => [
                'current_page', 'last_page', 'per_page', 'total',
                // 손조립을 표준 헬퍼로 바꾸며 확대된 3키 — 되돌아가면 여기서 red 가 된다.
                'from', 'to', 'has_more_pages',
            ]]]);

        $this->assertSame(1, $response->json('data.pagination.total'));
        $this->assertFalse($response->json('data.pagination.has_more_pages'));
    }

    // ================================================================
    // 주문 스코프 신청 내역 (마이페이지 주문상세 '이 주문의 클레임' 섹션)
    // ================================================================

    /**
     * 회원 목록·비회원 주문상세 섹션과 같은 계약(유형 서브탭 하나 · 페이지)이며, 이 주문의 것만 싣는다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=member, has_image=no
     *
     * @effects order_claim_section_lists_only_this_orders_requests
     */
    public function test_order_claims_lists_this_orders_requests_by_type(): void
    {
        $order = $this->myDeliveredOrder(quantity: 3);
        $claims = app(UserClaimService::class);
        $claims->requestReturn($order, ['items' => $this->items($order, 1), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'requested_by' => $this->user->id]);

        // 같은 회원의 다른 주문 반품은 이 주문 스코프에 실리지 않는다 — 회원 목록과 다른 점이다.
        $another = $this->myDeliveredOrder();
        $claims->requestReturn($another, ['items' => $this->items($another), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'requested_by' => $this->user->id]);

        $this->actingAs($this->user)->getJson($this->base()."/orders/{$order->id}/claims?type=return")
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.claim_type', 'return')
            ->assertJsonPath('data.data.0.order_number', $order->order_number)
            ->assertJsonPath('data.pagination.total', 1);

        $this->actingAs($this->user)->getJson($this->base()."/orders/{$order->id}/claims?type=exchange")
            ->assertOk()->assertJsonCount(0, 'data.data');
        $this->actingAs($this->user)->getJson($this->base()."/orders/{$order->id}/claims?type=cancel")
            ->assertOk()->assertJsonCount(0, 'data.data');
        $this->actingAs($this->user)->getJson($this->base()."/orders/{$order->id}/claims?type=bogus")
            ->assertStatus(422);
    }

    /**
     * 본인 주문이 아니면 존재를 드러내지 않고 404 다 — 형제 자원(claimable-options)과 같은 규칙.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=member, has_image=yes
     *
     * @effects order_claim_section_hides_other_members_orders
     */
    public function test_order_claims_hides_other_members_orders(): void
    {
        $other = $this->deliveredClaimOrder();
        app(UserClaimService::class)->requestReturn($other, ['items' => $this->items($other), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'requested_by' => $other->user_id]);

        // 익명 요청이 먼저다 — actingAs 는 같은 테스트 안의 뒤따르는 요청에도 남는다.
        $this->getJson($this->base()."/orders/{$other->id}/claims?type=return")
            ->assertUnauthorized();

        $this->actingAs($this->user)->getJson($this->base()."/orders/{$other->id}/claims?type=return")
            ->assertStatus(404);
    }

    /**
     * 유형을 지정하지 않으면 접수 건이 있는 첫 유형이 열린다 — 종전에는 늘 교환이라 취소만 있는
     * 주문에서 빈 안내가 먼저 보였다. 응답은 고른 유형(`claim_type`)과 유형별 건수(`counts`)를
     * 싣는다 — 화면은 그 둘로 활성 서브탭과 뱃지를 세운다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=member, has_image=yes
     *
     * @effects order_claim_section_default_type_is_first_with_requests, order_claim_section_response_carries_type_counts
     */
    public function test_order_claims_default_type_is_the_first_type_with_requests_and_carries_counts(): void
    {
        $order = $this->myDeliveredOrder(quantity: 3);
        app(UserClaimService::class)->requestReturn($order, ['items' => $this->items($order, 1), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'requested_by' => $this->user->id]);

        // 미지정 → 교환(0건)을 건너뛰고 반품
        $this->actingAs($this->user)->getJson($this->base()."/orders/{$order->id}/claims")
            ->assertOk()
            ->assertJsonPath('data.claim_type', 'return')
            ->assertJsonPath('data.counts', ['exchange' => 0, 'return' => 1, 'refund' => 0, 'cancel' => 0])
            ->assertJsonPath('data.data.0.claim_type', 'return')
            ->assertJsonPath('data.pagination.total', 1);

        // 빈 문자열은 미지정과 같다 — 주소에 유형이 없을 때 화면이 이렇게 보낸다(`?type=&page=1`)
        $this->actingAs($this->user)->getJson($this->base()."/orders/{$order->id}/claims?type=&page=1")
            ->assertOk()
            ->assertJsonPath('data.claim_type', 'return');

        // 지정하면 그 유형이다 — 비어 있어도 서버가 바꾸지 않는다(서브탭을 누른 상태·북마크)
        $this->actingAs($this->user)->getJson($this->base()."/orders/{$order->id}/claims?type=exchange")
            ->assertOk()
            ->assertJsonPath('data.claim_type', 'exchange')
            ->assertJsonCount(0, 'data.data')
            ->assertJsonPath('data.counts.return', 1);

        // 접수 건이 하나도 없으면 교환 — 종전 기본값 그대로
        $empty = $this->myDeliveredOrder();
        $this->actingAs($this->user)->getJson($this->base()."/orders/{$empty->id}/claims")
            ->assertOk()
            ->assertJsonPath('data.claim_type', 'exchange')
            ->assertJsonPath('data.counts', ['exchange' => 0, 'return' => 0, 'refund' => 0, 'cancel' => 0]);

        // 회원 신청내역(주문 횡단 목록)도 **같은 요약을 싣는다**.
        //
        // 예전에는 "그 화면은 주소의 유형이 늘 있고 뱃지도 없다" 를 전제로 싣지 않았는데,
        // 브라우저 실측이 그 전제를 부정했다 — 신청내역 화면의 서브탭은 `claims.data.counts`
        // 를 바인딩해 뱃지를 그리고 있었고, 서버가 그 키를 내지 않아 **뱃지가 영영 비어**
        // 있었다(F12). 구매자는 다른 탭에 건이 있는지 알 수 없어 네 탭을 차례로 눌러 봐야 했다.
        $this->actingAs($this->user)->getJson($this->base().'/claims?type=return')
            ->assertOk()
            ->assertJsonPath('data.claim_type', 'return')
            ->assertJsonPath('data.counts.return', 1);
    }

    /**
     * 우선순위는 교환 → 반품 → 취소 → 환불이다 (설계 결정 2026-09-09). 화면 서브탭의 배열 순서
     * (교환·반품·환불·취소)와 다르므로 배열 위치로 고르면 취소·환불이 둘 다 있을 때 갈린다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=member, has_image=no
     *
     * @effects order_claim_section_default_type_is_first_with_requests
     */
    public function test_order_claims_default_type_priority_is_exchange_return_cancel_refund(): void
    {
        $service = app(UserClaimService::class);

        $this->assertSame(ClaimTypeEnum::EXCHANGE, $service->resolveOrderListType(null, ['exchange' => 1, 'return' => 1, 'cancel' => 1, 'refund' => 1]));
        $this->assertSame(ClaimTypeEnum::RETURN, $service->resolveOrderListType(null, ['exchange' => 0, 'return' => 2, 'cancel' => 1, 'refund' => 3]));
        $this->assertSame(ClaimTypeEnum::CANCEL, $service->resolveOrderListType(null, ['cancel' => 1, 'refund' => 1]));
        $this->assertSame(ClaimTypeEnum::REFUND, $service->resolveOrderListType(null, ['refund' => 1]));
        $this->assertSame(ClaimTypeEnum::EXCHANGE, $service->resolveOrderListType(null, []));
        // 지정 값은 건수와 무관하다
        $this->assertSame(ClaimTypeEnum::REFUND, $service->resolveOrderListType(ClaimTypeEnum::REFUND, ['exchange' => 5]));

        // 실제 주문 — 교환과 반품이 함께 있으면 교환이 먼저다
        $order = $this->myDeliveredOrder(quantity: 3);
        $claims = app(UserClaimService::class);
        $claims->requestReturn($order, ['items' => $this->items($order, 1), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'requested_by' => $this->user->id]);
        $claims->requestExchange($order->fresh(['options']), ['items' => $this->items($order, 1), 'reason_code' => 'defective_product', 'charge_method' => 'deduct_from_refund', 'requested_by' => $this->user->id]);

        $this->actingAs($this->user)->getJson($this->base()."/orders/{$order->id}/claims")
            ->assertOk()
            ->assertJsonPath('data.claim_type', 'exchange')
            ->assertJsonPath('data.counts.exchange', 1)
            ->assertJsonPath('data.counts.return', 1)
            ->assertJsonPath('data.data.0.claim_type', 'exchange');
    }

    // ================================================================
    // 현금 0원(마일리지 전액) 주문 — 결함 4
    // ================================================================

    /**
     * 마일리지로 전액 결제한 무통장 주문은 돌려줄 현금이 없어 환불계좌를 묻지 않고, 자동 처리로 안내한다.
     *
     * @return void 반환값 없음
     *
     * @effects refund_account_required_false_for_mileage_only_order
     */
    public function test_claimable_options_needs_no_refund_account_for_a_mileage_only_bank_transfer_order(): void
    {
        $order = $this->mileageOnlyPaidOrder($this->user, PaymentMethodEnum::DBANK->value);

        $this->actingAs($this->user)->getJson($this->base()."/orders/{$order->id}/claimable-options")
            ->assertOk()
            ->assertJsonPath('data.refund_account.required', false)
            ->assertJsonPath('data.refund_account.banks', [])
            ->assertJsonPath('data.refund_account.prefill', null)
            ->assertJsonPath('data.refund_auto_approved', true);
    }

    /**
     * 현금과 마일리지를 함께 쓴 무통장 주문은 종전대로 환불계좌를 묻는다 — 돌려줄 현금이 있다(비회귀).
     *
     * @return void 반환값 없음
     *
     * @effects mixed_cash_order_keeps_bank_requirement
     */
    public function test_claimable_options_still_requires_an_account_for_a_mixed_cash_and_mileage_bank_order(): void
    {
        $order = $this->deliveredClaimOrder(
            orderOverrides: ['total_points_used_amount' => 5000, 'is_mileage_deducted' => true],
            paymentOverrides: ['payment_method' => PaymentMethodEnum::DBANK->value],
        );
        $order->forceFill(['user_id' => $this->user->id])->save();

        $this->actingAs($this->user)->getJson($this->base()."/orders/{$order->id}/claimable-options")
            ->assertOk()
            ->assertJsonPath('data.refund_account.required', true);
    }

    /**
     * 마일리지로 전액 결제한 주문의 반품은 계좌 없이 접수되고, 완료 처리로 생긴 환불신청서는
     * 「마일리지로 환불」로 기록된다.
     *
     * @return void 반환값 없음
     *
     * @effects mileage_only_refund_is_recorded_as_points
     */
    public function test_return_request_of_a_mileage_only_order_needs_no_account(): void
    {
        $order = $this->mileageOnlyPaidOrder($this->user, PaymentMethodEnum::DBANK->value, '', OrderStatusEnum::DELIVERED);

        $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/returns", [
            'items' => $this->items($order), 'reason_code' => 'defective_product', 'charge_method' => 'deduct_from_refund',
        ])->assertCreated();

        $service = app(OrderReturnService::class);
        $return = OrderReturn::query()->where('order_id', $order->id)->firstOrFail();
        $return = $service->transition($return, ReturnStatusEnum::APPROVED);
        $return = $service->transition($return, ReturnStatusEnum::COLLECTED);
        $service->completeReturn($return, [], new ClaimCompletionOptions(restoreStockByItem: [], createRefundRequest: true));

        $refund = OrderRefund::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(RefundMethodEnum::POINTS, $refund->refund_method);
        $this->assertSame(0.0, (float) $refund->refund_amount);
    }
}
