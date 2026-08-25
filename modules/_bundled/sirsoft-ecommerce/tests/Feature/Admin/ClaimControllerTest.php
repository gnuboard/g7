<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Contracts\Extension\StorageInterface;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 클레임 API Feature 테스트 (S6a)
 *
 * 검증 축:
 * - 목록 — 유형 서브탭 페이지네이션 + 유형별 건수 뱃지 + 상태 배열 필터(유형별 어휘 게이트)
 *   + 검색 4종 + 유형에 없는 필터 축 prohibited + 권한 403
 * - 상세 — 관리자 확장 필드 + 결제 블록 + 404
 * - 단건 전이 — 승인-결제 상호작용(pay_on_approval 결제 요청 우회 / pay_on_request 미수납 422
 *   / 청구 없는 결제 요청 422 = paymentNotRequired 배선) + 쓰기 게이트(paid 거절) + 전이 게이트 422
 * - 완료(D26) — completion 페이로드 필수, create_refund_request false 시 order_refunds 0건 / true 시 1건
 * - 일괄 전이 — 부분 성공 계약(게이트 위반 skip 리포트)
 * - 상품 단위 전이(D25) — 상위 스코프 422(다른 신청서의 행) + 완료 경로
 * - 송장 — 회수 송장 등록 + collecting 자동 전이 + 불완전 422
 * - 첨부 — 운영자 대리 첨부(uploadFor)·일괄 정리(deleteFor) 소비
 */
class ClaimControllerTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.claims.read',
            'sirsoft-ecommerce.claims.update-status',
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
        return '/api/modules/sirsoft-ecommerce/admin';
    }

    /**
     * 반품 신청서를 서비스 경로로 접수합니다. (기본: 환불금 차감 — 자동 승인 없이 REQUESTED 유지)
     */
    protected function makeReturn(?Order $order = null, ClaimChargeMethodEnum|string|null $chargeMethod = ClaimChargeMethodEnum::DEDUCT_FROM_REFUND): OrderReturn
    {
        $order ??= $this->deliveredClaimOrder();

        return app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => $chargeMethod,
        ]);
    }

    /**
     * 교환 신청서를 서비스 경로로 접수합니다. (동일 옵션 교환 — 왕복 배송비 청구)
     */
    protected function makeExchange(?Order $order = null, ClaimChargeMethodEnum|string|null $chargeMethod = ClaimChargeMethodEnum::PAY_ON_APPROVAL): OrderExchange
    {
        $order ??= $this->deliveredClaimOrder();

        return app(OrderExchangeService::class)->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
            'charge_method' => $chargeMethod,
        ]);
    }

    /**
     * 반품 신청서를 회수도착까지 진행시킵니다. (완료 직전 상태)
     */
    protected function moveReturnToCollected(OrderReturn $return): OrderReturn
    {
        $service = app(OrderReturnService::class);
        $return = $service->transition($return, ReturnStatusEnum::APPROVED);
        $return = $service->transition($return, ReturnStatusEnum::COLLECTION_READY);
        $return = $service->transition($return, ReturnStatusEnum::COLLECTING);

        return $service->transition($return, ReturnStatusEnum::COLLECTED);
    }

    // ================================================================
    // 목록
    // ================================================================

    public function test_list_returns_subtab_rows_with_counts_and_pagination(): void
    {
        $return = $this->makeReturn();
        $this->makeExchange();

        $response = $this->actingAs($this->admin)->getJson($this->base().'/claims?type=return');

        $response->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.claim_number', $return->return_number)
            ->assertJsonPath('data.data.0.claim_type', 'return')
            ->assertJsonPath('data.data.0.status', 'requested')
            ->assertJsonPath('data.data.0.item_summary.item_count', 1)
            ->assertJsonPath('data.counts.return', 1)
            ->assertJsonPath('data.counts.exchange', 1)
            ->assertJsonPath('data.counts.refund', 0)
            ->assertJsonPath('data.counts.cancel', 0)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.abilities.can_update_status', true);

        // 목록 행은 축소 표현이다 — 상품 전량(items)·첨부(images)를 싣지 않는다.
        $this->assertArrayNotHasKey('items', $response->json('data.data.0'));
        $this->assertArrayNotHasKey('images', $response->json('data.data.0'));
    }

    public function test_list_defaults_to_the_exchange_subtab(): void
    {
        $exchange = $this->makeExchange();
        $this->makeReturn();

        $this->actingAs($this->admin)->getJson($this->base().'/claims')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.claim_number', $exchange->exchange_number);
    }

    public function test_list_filters_by_status_array_and_gates_vocabulary_per_type(): void
    {
        $approved = $this->makeReturn();
        app(OrderReturnService::class)->transition($approved, ReturnStatusEnum::APPROVED);
        $this->makeReturn();

        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=return&statuses[]=approved')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.status', 'approved');

        // 재발송중은 교환 어휘다 — 반품 탭에서는 422 (오타·다른 유형 상태가 빈 목록으로 위장하지 않게).
        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=return&statuses[]=redelivering')
            ->assertStatus(422);
    }

    public function test_list_searches_by_claim_number(): void
    {
        $target = $this->makeReturn();
        $this->makeReturn();

        $this->actingAs($this->admin)
            ->getJson($this->base().'/claims?type=return&search_field=claim_number&search_keyword='.$target->return_number)
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $target->id);
    }

    public function test_list_prohibits_type_foreign_filter_axes(): void
    {
        // 취소에는 귀책 축이 없다 — 조용히 무시하면 필터가 안 걸린 목록이 필터 결과로 보인다.
        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=cancel&fault_types[]=seller')
            ->assertStatus(422);

        // 환불에는 청구 방식 축이 없다.
        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=refund&charge_methods[]=pay_on_request')
            ->assertStatus(422);
    }

    public function test_cancel_subtab_reports_is_final_from_the_cancel_status(): void
    {
        $order = $this->deliveredClaimOrder();

        // 취소는 REQUESTED 로 생성된 뒤 파이프라인 완료 시 COMPLETED 로 전이한다 — 중간·실패
        // 경로의 미종결 행이 목록에서 종결(is_final=true)로 보이면 무음 오표시다.
        OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => 'CL-TEST-REQ-1',
            'cancel_type' => CancelTypeEnum::FULL,
            'cancel_status' => CancelStatusEnum::REQUESTED,
            'cancel_reason_type' => 'change_of_mind',
            'items_snapshot' => [],
        ]);

        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=cancel')
            ->assertOk()
            ->assertJsonPath('data.data.0.status', 'requested')
            ->assertJsonPath('data.data.0.is_final', false);
    }

    public function test_list_requires_the_claims_read_permission(): void
    {
        $noPermission = $this->createAdminUser([]);

        $this->actingAs($noPermission)->getJson($this->base().'/claims')->assertForbidden();
    }

    // ================================================================
    // 상세
    // ================================================================

    public function test_show_returns_admin_fields_and_payment_block(): void
    {
        $return = $this->makeReturn();

        $response = $this->actingAs($this->admin)->getJson($this->base()."/returns/{$return->id}");

        $response->assertOk()
            ->assertJsonPath('data.claim.claim_number', $return->return_number)
            ->assertJsonPath('data.claim.requester_name', $return->order->orderer_name)
            ->assertJsonPath('data.claim.outstanding_amount', 0);

        // 관리자 확장 필드는 추가만 된다 — 유저 응답 키는 그대로 존재한다.
        $claim = $response->json('data.claim');
        $this->assertArrayHasKey('admin_memo', $claim);
        $this->assertArrayHasKey('processed_by', $claim);
        $this->assertArrayHasKey('payments', $claim);
        $this->assertArrayHasKey('items', $claim);
    }

    public function test_show_is_404_for_a_missing_claim(): void
    {
        $this->actingAs($this->admin)->getJson($this->base().'/returns/999999')->assertNotFound();
    }

    // ================================================================
    // 단건 전이 — 승인·결제 상호작용
    // ================================================================

    public function test_status_approves_a_claim_without_outstanding_charge(): void
    {
        $return = $this->makeReturn();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'approved',
        ])->assertOk()
            ->assertJsonPath('data.claim.status', 'approved')
            ->assertJsonPath('data.payment_requested', false);

        $this->assertSame(ReturnStatusEnum::APPROVED, $return->fresh()->return_status);
        $this->assertSame($this->admin->id, $return->fresh()->processed_by);
    }

    public function test_status_approve_with_pay_on_approval_outstanding_routes_to_payment_request(): void
    {
        $return = $this->makeReturn(chargeMethod: ClaimChargeMethodEnum::PAY_ON_APPROVAL);

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'approved',
        ])->assertOk()
            ->assertJsonPath('data.payment_requested', true)
            ->assertJsonPath('data.claim.status', 'payment_pending');

        // 승인이 아니라 결제 요청이 일어났다 — CP 결제 대기 건이 만들어지고 승인 시각은 비어 있다.
        $payment = ClaimPayment::query()
            ->where('payable_type', ClaimTypeEnum::RETURN->value)
            ->where('payable_id', $return->id)
            ->first();

        $this->assertNotNull($payment);
        $this->assertTrue($payment->isPending());
        $this->assertNull($return->fresh()->approved_at);
    }

    public function test_status_approve_with_pay_on_request_outstanding_is_rejected(): void
    {
        // 신청 시점 결제 미수납 건 — 승인하면 청구액이 회수되지 않은 채 진행되므로 거절한다.
        $order = $this->deliveredClaimOrder();
        ['claim' => $return] = app(UserClaimService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => 'pay_on_request',
        ], [], 'card');

        $this->assertSame(ReturnStatusEnum::PAYMENT_PENDING, $return->fresh()->return_status);

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'approved',
        ])->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_payment_outstanding'));

        $this->assertSame(ReturnStatusEnum::PAYMENT_PENDING, $return->fresh()->return_status);
    }

    public function test_status_payment_pending_without_charge_is_payment_not_required(): void
    {
        // 환불금 차감 신청서에는 결제할 청구가 없다 — 결제 요청은 paymentNotRequired 로 거절된다.
        $return = $this->makeReturn();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'payment_pending',
        ])->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_payment_not_required'));

        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh()->return_status);
    }

    public function test_status_write_gate_rejects_owned_path_statuses(): void
    {
        $return = $this->makeReturn();

        // paid 는 결제 확정, withdrawn 은 신청자 행위 소유다 — 직접 지정은 검증 단계에서 막힌다.
        foreach (['paid', 'withdrawn', 'requested'] as $owned) {
            $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
                'status' => $owned,
            ])->assertStatus(422);
        }

        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh()->return_status);
    }

    public function test_status_transition_gate_rejects_an_impossible_jump(): void
    {
        $return = $this->makeReturn();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'collected',
        ])->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_transition_not_allowed', [
                'from' => ReturnStatusEnum::REQUESTED->label(),
                'to' => ReturnStatusEnum::COLLECTED->label(),
            ]));
    }

    public function test_status_rejection_stores_the_reject_reason(): void
    {
        $return = $this->makeReturn();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'rejected',
            'reject_reason' => '재고 확인 결과 반품 대상이 아닙니다.',
        ])->assertOk()
            ->assertJsonPath('data.claim.status', 'rejected');

        $this->assertSame('재고 확인 결과 반품 대상이 아닙니다.', $return->fresh()->reject_reason);
    }

    // ================================================================
    // 완료 처리 (D26)
    // ================================================================

    public function test_status_completed_requires_the_completion_payload(): void
    {
        $return = $this->moveReturnToCollected($this->makeReturn());

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'completed',
        ])->assertStatus(422);

        $this->assertSame(ReturnStatusEnum::COLLECTED, $return->fresh()->return_status);
    }

    public function test_status_completed_without_refund_request_creates_no_order_refund(): void
    {
        $return = $this->moveReturnToCollected($this->makeReturn());
        $item = $return->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'completed',
            'completion' => [
                'restore_stock_by_item' => [$item->id => true],
                'create_refund_request' => false,
            ],
        ])->assertOk()
            ->assertJsonPath('data.claim.status', 'completed');

        $this->assertSame(0, OrderRefund::query()->where('order_id', $return->order_id)->count());
        $this->assertNull($return->fresh()->refund_id);
    }

    public function test_status_completed_with_refund_request_creates_the_order_refund(): void
    {
        $return = $this->moveReturnToCollected($this->makeReturn());
        $item = $return->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'completed',
            'completion' => [
                'restore_stock_by_item' => [$item->id => true],
                'create_refund_request' => true,
            ],
        ])->assertOk()
            ->assertJsonPath('data.claim.status', 'completed');

        $fresh = $return->fresh();
        $this->assertNotNull($fresh->refund_id);
        $this->assertSame(1, OrderRefund::query()->where('order_id', $return->order_id)->count());
    }

    // ================================================================
    // 일괄 전이 — 부분 성공 계약
    // ================================================================

    public function test_bulk_status_reports_partial_success_with_skip_reasons(): void
    {
        $approvable = $this->makeReturn();
        $rejected = $this->makeReturn();
        app(OrderReturnService::class)->transition($rejected, ReturnStatusEnum::REJECTED);

        $response = $this->actingAs($this->admin)->postJson($this->base().'/claims/bulk-status', [
            'type' => 'return',
            'ids' => [$approvable->id, $rejected->id, 999999],
            'status' => 'approved',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.succeeded.0', $approvable->id)
            ->assertJsonPath('data.payment_requested', []);

        $skipped = collect($response->json('data.skipped'));
        $this->assertCount(2, $skipped);
        $this->assertSame(
            __('sirsoft-ecommerce::exceptions.claim_not_found'),
            $skipped->firstWhere('id', 999999)['message']
        );
        $this->assertSame($rejected->return_number, $skipped->firstWhere('id', $rejected->id)['claim_number']);
        $this->assertSame(ReturnStatusEnum::APPROVED, $approvable->fresh()->return_status);
        $this->assertSame(ReturnStatusEnum::REJECTED, $rejected->fresh()->return_status);
    }

    public function test_bulk_status_requires_the_update_permission(): void
    {
        $readOnly = $this->createAdminUser(['sirsoft-ecommerce.claims.read']);

        $this->actingAs($readOnly)->postJson($this->base().'/claims/bulk-status', [
            'type' => 'return',
            'ids' => [1],
            'status' => 'approved',
        ])->assertForbidden();
    }

    // ================================================================
    // 상품 단위 일괄 전이 (D25)
    // ================================================================

    public function test_option_bulk_rejects_items_of_another_claim_with_422(): void
    {
        $mine = $this->makeReturn();
        $other = $this->makeReturn();
        $foreignItem = $other->fresh('returnOptions')->returnOptions->first();

        // 다른 신청서의 상품 행은 서비스의 무음 탈락("0건 처리됨")에 닿기 전에 422 로 드러난다.
        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$mine->id}/options/bulk-status", [
            'items' => [$foreignItem->id],
            'status' => 'approved',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.0']);
    }

    public function test_option_bulk_transitions_items_and_syncs_the_header(): void
    {
        $return = $this->makeReturn();
        app(OrderReturnService::class)->transition($return, ReturnStatusEnum::APPROVED);
        $item = $return->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/options/bulk-status", [
            'items' => [$item->id],
            'status' => 'collection_ready',
        ])->assertOk()
            ->assertJsonPath('data.succeeded.0', $item->id)
            ->assertJsonPath('data.skipped', []);

        // 단일 상품이 전이됐으므로 헤더가 따라간다 (syncHeaderFromOptions).
        $this->assertSame(ReturnStatusEnum::COLLECTION_READY, $return->fresh()->return_status);
    }

    public function test_option_bulk_completed_completes_the_selected_items(): void
    {
        $return = $this->moveReturnToCollected($this->makeReturn());
        $item = $return->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/options/bulk-status", [
            'items' => [$item->id],
            'status' => 'completed',
            'completion' => [
                'restore_stock_by_item' => [$item->id => false],
                'create_refund_request' => false,
            ],
        ])->assertOk()
            ->assertJsonPath('data.succeeded.0', $item->id)
            ->assertJsonPath('data.claim.status', 'completed');

        $this->assertSame(ReturnStatusEnum::COMPLETED, $item->fresh()->option_status);
        $this->assertSame(0, OrderRefund::query()->where('order_id', $return->order_id)->count());
    }

    // ================================================================
    // 송장
    // ================================================================

    public function test_collection_tracking_registers_and_moves_to_collecting(): void
    {
        $return = $this->makeReturn();
        $service = app(OrderReturnService::class);
        $service->transition($return, ReturnStatusEnum::APPROVED);
        $service->transition($return->fresh(), ReturnStatusEnum::COLLECTION_READY);

        $carrier = ShippingCarrier::first() ?? ShippingCarrier::create([
            'code' => 'test-carrier',
            'name' => ['ko' => '테스트택배', 'en' => 'Test Carrier'],
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->putJson($this->base()."/returns/{$return->id}/collection-tracking", [
            'carrier_id' => $carrier->id,
            'tracking_number' => '1234567890',
        ])->assertOk()
            ->assertJsonPath('data.claim.status', 'collecting')
            ->assertJsonPath('data.claim.collection_tracking_number', '1234567890');
    }

    public function test_redelivery_tracking_registers_and_moves_to_redelivering(): void
    {
        // 왕복 배송비 0원 교환 — 미수납 청구 없이 승인부터 재발송준비중까지 진행시킨다.
        $order = $this->deliveredClaimOrder(exchangeFee: 0);
        $exchange = app(OrderExchangeService::class)->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
        ]);

        $service = app(OrderExchangeService::class);
        $exchange = $exchange->fresh();

        foreach ([
            ExchangeStatusEnum::APPROVED,
            ExchangeStatusEnum::COLLECTION_READY,
            ExchangeStatusEnum::COLLECTING,
            ExchangeStatusEnum::COLLECTED,
            ExchangeStatusEnum::REDELIVERY_READY,
        ] as $step) {
            $exchange = $service->transition($exchange, $step);
        }

        $carrier = ShippingCarrier::first() ?? ShippingCarrier::create([
            'code' => 'test-carrier',
            'name' => ['ko' => '테스트택배', 'en' => 'Test Carrier'],
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->putJson($this->base()."/exchanges/{$exchange->id}/redelivery-tracking", [
            'carrier_id' => $carrier->id,
            'tracking_number' => '9876543210',
        ])->assertOk()
            ->assertJsonPath('data.claim.status', 'redelivering')
            ->assertJsonPath('data.claim.redelivery_tracking_number', '9876543210');
    }

    public function test_collection_tracking_rejects_an_incomplete_pair(): void
    {
        $return = $this->makeReturn();

        $this->actingAs($this->admin)->putJson($this->base()."/returns/{$return->id}/collection-tracking", [
            'tracking_number' => '1234567890',
        ])->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_tracking_incomplete'));
    }

    // ================================================================
    // 운영자 대리 첨부 / 첨부 일괄 정리
    // ================================================================

    public function test_admin_uploads_and_clears_claim_attachments(): void
    {
        $return = $this->makeReturn();

        $upload = $this->actingAs($this->admin)->post($this->base()."/returns/{$return->id}/images", [
            'image' => UploadedFile::fake()->image('evidence.jpg'),
        ]);

        $upload->assertCreated()
            ->assertJsonPath('data.image.claimable_type', 'return')
            ->assertJsonPath('data.image.claimable_id', $return->id);

        $this->assertCount(1, app(ClaimAttachmentService::class)->listFor(ClaimTypeEnum::RETURN, $return->id));

        $this->actingAs($this->admin)->deleteJson($this->base()."/returns/{$return->id}/images")
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertCount(0, app(ClaimAttachmentService::class)->listFor(ClaimTypeEnum::RETURN, $return->id));
    }

    public function test_image_upload_is_404_for_a_missing_claim(): void
    {
        $this->actingAs($this->admin)->post($this->base().'/returns/999999/images', [
            'image' => UploadedFile::fake()->image('evidence.jpg'),
        ])->assertNotFound();
    }
}
