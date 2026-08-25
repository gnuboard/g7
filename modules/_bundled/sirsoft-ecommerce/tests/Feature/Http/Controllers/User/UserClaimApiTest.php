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
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
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

    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        Mockery::close();
        parent::tearDown();
    }

    protected function base(): string
    {
        return '/api/modules/sirsoft-ecommerce/user';
    }

    /**
     * 이 회원의 배송완료 주문을 만듭니다.
     */
    protected function myDeliveredOrder(int $quantity = 1, int $returnFee = 3000): Order
    {
        $order = $this->deliveredClaimOrder(quantity: $quantity, returnFee: $returnFee);
        $order->forceFill(['user_id' => $this->user->id])->save();

        return $order->fresh(['options', 'shippingAddress']);
    }

    protected function myPaidOrder(): Order
    {
        $order = $this->paidClaimOrder();
        $order->forceFill(['user_id' => $this->user->id])->save();

        return $order->fresh(['options']);
    }

    protected function items(Order $order, int $quantity = 1): array
    {
        return [['order_option_id' => $order->options->first()->id, 'quantity' => $quantity]];
    }

    // ================================================================
    // 신청 가능 옵션 / 미리보기
    // ================================================================

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

    public function test_claimable_options_is_404_for_someone_elses_order(): void
    {
        $order = $this->deliveredClaimOrder();

        $this->actingAs($this->user)->getJson($this->base()."/orders/{$order->id}/claimable-options")->assertNotFound();
    }

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

    public function test_refund_request_branches_to_instant_cancel_for_paid_items(): void
    {
        $order = $this->myPaidOrder();

        $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/refund-requests", [
            'items' => $this->items($order), 'reason_code' => 'changed_mind',
        ])->assertCreated()
            ->assertJsonPath('data.branch', 'cancel')
            ->assertJsonPath('data.claim.claim_type', 'cancel');

        $this->assertSame(OrderStatusEnum::CANCELLED, $order->fresh()->order_status);
    }

    public function test_refund_request_files_a_refund_request_for_preparing_items(): void
    {
        $order = $this->myPaidOrder();
        $order->options->first()->forceFill(['option_status' => OrderStatusEnum::PREPARING])->save();
        $order->forceFill(['order_status' => OrderStatusEnum::PREPARING])->save();

        $this->actingAs($this->user)->postJson($this->base()."/orders/{$order->id}/refund-requests", [
            'items' => $this->items($order), 'reason_code' => 'changed_mind', 'reason_detail' => '변심',
        ])->assertCreated()
            ->assertJsonPath('data.branch', 'refund')
            ->assertJsonPath('data.claim.claim_type', 'refund')
            ->assertJsonPath('data.claim.status', 'requested')
            ->assertJsonPath('data.claim.refund_source_type', 'direct')
            ->assertJsonPath('data.claim.refund_total', 29000);

        $this->assertSame(1, OrderRefund::count());
    }

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

    public function test_index_lists_claims_by_type_with_pagination(): void
    {
        $order = $this->myDeliveredOrder(quantity: 3);
        $claims = app(UserClaimService::class);
        $claims->requestReturn($order, ['items' => $this->items($order, 1), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'requested_by' => $this->user->id]);
        $claims->requestExchange($order->fresh(['options']), ['items' => $this->items($order, 1), 'reason_code' => 'size_mismatch', 'charge_method' => 'deduct_from_refund', 'requested_by' => $this->user->id]);
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

    public function test_update_cannot_pick_a_reason_that_is_hidden_from_users(): void
    {
        // 접수 FormRequest 는 `is_user_selectable=true` 까지 게이트하는데 수정 요청은 신청서 유형을
        // 몰라 규칙을 걸 수 없다. 서비스가 그 조건을 보지 않으면, 화면에 뜨지도 않는 판매자 귀책
        // 사유를 수정으로 지정해 회수 배송비를 0 으로 만들 수 있다.
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
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_reason_unavailable'));

        $fresh = $return->fresh();
        $this->assertSame($before->reason_code, $fresh->reason_code);
        $this->assertSame($before->fault_type, $fresh->fault_type);
        $this->assertEqualsWithDelta((float) $before->claim_shipping_fee, (float) $fresh->claim_shipping_fee, 0.001);
    }

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
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_image_limit_exceeded', ['max' => 5]));

        $fresh = $return->fresh();
        $this->assertSame('changed_mind', $fresh->reason_code, '거절된 수정이 사유를 바꿔 놓으면 안 됩니다.');
        $this->assertSame(
            3,
            ClaimImage::where('claimable_type', 'return')->where('claimable_id', $return->id)->count(),
            '거절된 수정이 첨부를 지우면 사용자는 스스로 되돌릴 수 없습니다.',
        );
    }

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

    public function test_claim_read_requires_the_read_permission(): void
    {
        $this->actingAs($this->userWithoutClaimPermission('read'))
            ->getJson($this->base().'/claims')
            ->assertForbidden();
    }

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
        $today = now()->toDateString();

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
    // 취소 미리보기 비대칭 해소 (S3 2차 PO 확정 ② — 회원 면)
    // ================================================================

    /**
     * 클레임 코스에 잠긴 상품은 회원 취소 미리보기도 422 다.
     *
     * `previewRefund()` 의 클레임 가드가 기본 적용되면서 회원 면에도 새 422 경로가 생겼다.
     * 관리자 면만 단언하면 이 컨트롤러의 매핑(`OrderCancellationException` → 422)이
     * 끊겨도 회귀가 초록으로 남는다 — 세 면이 각각 다른 메시지 키를 쓰기 때문에 더 그렇다.
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
}
