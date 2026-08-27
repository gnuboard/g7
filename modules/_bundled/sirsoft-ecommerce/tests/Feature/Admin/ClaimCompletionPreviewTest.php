<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Extension\HookManager;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 완료 처리 미리보기·환불신청서 수동 접수·클레임 금액 수정 Feature 테스트 (S6b)
 *
 * 검증 축:
 * - completion-preview (D26) — 반품 환불 예정액·`hasStockRestoreTarget()` 배선·재고 복구/폐기 건수
 *   + 교환의 접수 불가 사유 구분 + 미존재 신청서 skip 리포트 + 권한 403
 * - refund-request (D26) — 완료 시 접수하지 않은 반품의 수동 접수 / 이미 접수 422 / 환불 대상 없음 422
 * - 금액 수정 (D18, 교환·반품 축) — 청구 0원 상향의 charge_method 동반 계약 + 허용 목록 게이트
 *   + 종결 신청서 422 + 수정 전후 훅(`claim.{type}.amounts_updated`) 발화
 */
class ClaimCompletionPreviewTest extends ModuleTestCase
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
            'sirsoft-ecommerce.claims.update-amounts',
        ]);
    }

    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    protected function base(): string
    {
        return '/api/modules/sirsoft-ecommerce/admin';
    }

    /**
     * 회수도착까지 진행된 반품을 만듭니다. (완료 직전 상태 — 환불금 차감)
     *
     * @return array{0: Order, 1: OrderReturn} 주문 + 반품
     */
    protected function collectedReturn(): array
    {
        $order = $this->deliveredClaimOrder();
        $service = app(OrderReturnService::class);

        $return = $service->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);

        $return = $service->transition($return, ReturnStatusEnum::APPROVED);
        $return = $service->transition($return, ReturnStatusEnum::COLLECTION_READY);
        $return = $service->transition($return, ReturnStatusEnum::COLLECTING);
        $return = $service->transition($return, ReturnStatusEnum::COLLECTED);

        return [$order, $return->fresh(['returnOptions'])];
    }

    /**
     * 동일 옵션 교환(차액 0)을 접수합니다.
     *
     * @return array{0: Order, 1: OrderExchange} 주문 + 교환
     */
    protected function sameOptionExchange(): array
    {
        $order = $this->deliveredClaimOrder();

        $exchange = app(OrderExchangeService::class)->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_APPROVAL,
        ]);

        return [$order, $exchange->fresh(['exchangeOptions'])];
    }

    // ================================================================
    // 완료 처리 미리보기 (D26)
    // ================================================================

    public function test_completion_preview_reports_the_return_refund_estimate_and_stock_choices(): void
    {
        [, $return] = $this->collectedReturn();
        $itemId = $return->returnOptions->first()->id;

        $response = $this->actingAs($this->admin)
            ->postJson($this->base().'/claims/completion-preview', [
                'claim_type' => 'return',
                'create_refund_request' => true,
                'claims' => [[
                    'id' => $return->id,
                    'restore_stock_by_item' => [$itemId => true],
                ]],
            ]);

        $response->assertStatus(200);

        $row = $response->json('data.claims.0');
        $this->assertSame($return->id, $row['id']);
        $this->assertSame([$itemId], $row['completable_item_ids']);
        // 재고 복구 대상 유무는 ClaimCompletionOptions::hasStockRestoreTarget() 가 판정한다 (S3 3차 PO 확정 ⑥).
        $this->assertTrue($row['has_stock_restore_target']);
        $this->assertSame(1, $row['stock_restore_count']);
        $this->assertSame(0, $row['stock_discard_count']);
        $this->assertTrue($row['refund']['applicable']);
        // 29,000 상품 − 회수배송비 3,000 차감 = 26,000 환불 예정.
        $this->assertSame(26000.0, (float) $row['refund']['refund_amount']);
        $this->assertSame(3000.0, (float) $row['refund']['deducted_shipping_fee']);

        $this->assertSame(26000.0, (float) $response->json('data.totals.refund_amount_total'));
    }

    public function test_completion_preview_reflects_the_unchecked_stock_and_no_refund_choice(): void
    {
        [, $return] = $this->collectedReturn();
        $itemId = $return->returnOptions->first()->id;

        $response = $this->actingAs($this->admin)
            ->postJson($this->base().'/claims/completion-preview', [
                'claim_type' => 'return',
                'create_refund_request' => false,
                'claims' => [[
                    'id' => $return->id,
                    'restore_stock_by_item' => [$itemId => false],
                ]],
            ]);

        $response->assertStatus(200);

        $row = $response->json('data.claims.0');
        $this->assertFalse($row['has_stock_restore_target']);
        $this->assertSame(0, $row['stock_restore_count']);
        $this->assertSame(1, $row['stock_discard_count']);
        // '접수하지 않음' — 환불 예정액은 참고값으로 남고 접수 대상은 아니다.
        $this->assertFalse($row['refund']['applicable']);
        $this->assertSame(0.0, (float) $response->json('data.totals.refund_amount_total'));
    }

    public function test_completion_preview_distinguishes_the_exchange_without_a_refundable_difference(): void
    {
        [, $exchange] = $this->sameOptionExchange();

        $response = $this->actingAs($this->admin)
            ->postJson($this->base().'/claims/completion-preview', [
                'claim_type' => 'exchange',
                'create_refund_request' => true,
                'claims' => [['id' => $exchange->id]],
            ]);

        $response->assertStatus(200);

        $row = $response->json('data.claims.0');
        // 동일 옵션 교환 — 차액 환불이 성립하지 않는다 (claim_refund_not_applicable 구분 안내).
        $this->assertFalse($row['refund']['applicable']);
        $this->assertSame('claim_refund_not_applicable', $row['refund']['blocked_reason']);
    }

    public function test_completion_preview_reports_the_exchange_refundable_difference(): void
    {
        [, $exchange] = $this->redeliveredLowerPricedExchange();

        $this->assertGreaterThan(0, (float) $exchange->refund_product_amount, '저가 교환의 동결 차액이 픽스처의 전제다.');

        $response = $this->actingAs($this->admin)
            ->postJson($this->base().'/claims/completion-preview', [
                'claim_type' => 'exchange',
                'create_refund_request' => true,
                'claims' => [['id' => $exchange->id]],
            ]);

        $response->assertStatus(200);

        $row = $response->json('data.claims.0');

        // 저가 교환 — 완료하면 차액이 접수 가능하다. 미리보기 금액은 접수 경로와 같은 산식이어야
        // 모달 안내와 실제 접수액이 갈리지 않는다 (인계 판정 ⑨).
        $this->assertTrue($row['refund']['applicable']);
        $this->assertNull($row['refund']['blocked_reason']);
        $this->assertSame(
            (float) $exchange->refund_product_amount,
            (float) $row['refund']['refund_amount'] + (float) $row['refund']['refund_points_amount']
                + (float) $row['refund']['deducted_shipping_fee'],
            '미리보기 총액은 교환이 동결한 차액과 같은 단위로 떨어진다.',
        );
    }

    public function test_refund_request_files_the_exchange_difference_after_completing_without_one(): void
    {
        [, $exchange] = $this->redeliveredLowerPricedExchange();

        // 완료 시점에는 접수하지 않는다 — D26 의 "접수는 선택" 경로.
        app(OrderExchangeService::class)->completeExchange(
            $exchange,
            [],
            new ClaimCompletionOptions(restoreStockByItem: [], createRefundRequest: false),
        );

        $this->assertSame(0, OrderRefund::where('order_id', $exchange->order_id)->count());

        $this->actingAs($this->admin)
            ->postJson($this->base()."/exchanges/{$exchange->id}/refund-request")
            ->assertStatus(201);

        $refund = OrderRefund::where('order_id', $exchange->order_id)->firstOrFail();

        $this->assertSame(RefundSourceTypeEnum::EXCHANGE, $refund->refund_source_type);
        $this->assertSame($exchange->id, (int) $refund->refund_source_id);
        $this->assertGreaterThan(0, (float) $refund->refund_amount + (float) $refund->refund_points_amount);

        // 두 번째 접수는 담을 것이 없다 — 이미 접수된 상품은 되짚어 제외된다 (S4 확정 N건 계약).
        $this->actingAs($this->admin)
            ->postJson($this->base()."/exchanges/{$exchange->id}/refund-request")
            ->assertStatus(422);
    }

    public function test_completion_preview_skips_a_missing_claim_with_a_report(): void
    {
        [, $return] = $this->collectedReturn();

        $response = $this->actingAs($this->admin)
            ->postJson($this->base().'/claims/completion-preview', [
                'claim_type' => 'return',
                'create_refund_request' => false,
                'claims' => [['id' => $return->id], ['id' => 999999]],
            ]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.claims'));
        $this->assertSame(999999, $response->json('data.skipped.0.id'));
    }

    public function test_completion_preview_rejects_item_ids_from_another_claim(): void
    {
        [, $return] = $this->collectedReturn();
        [, $other] = $this->collectedReturn();
        $foreignItemId = $other->returnOptions->first()->id;

        $response = $this->actingAs($this->admin)
            ->postJson($this->base().'/claims/completion-preview', [
                'claim_type' => 'return',
                'create_refund_request' => true,
                'claims' => [[
                    'id' => $return->id,
                    'item_ids' => [$foreignItemId],
                ]],
            ]);

        // 남의 신청서 행 ID 는 조용히 버려지면 안 된다 — 그 값이 완료 실행의 근거가 되기 때문이다.
        $response->assertStatus(422)
            ->assertJsonValidationErrors('claims.0.item_ids.0');
    }

    public function test_completion_preview_requires_the_update_status_permission(): void
    {
        $reader = $this->createAdminUser(['sirsoft-ecommerce.claims.read']);

        $this->actingAs($reader)
            ->postJson($this->base().'/claims/completion-preview', [
                'claim_type' => 'return',
                'create_refund_request' => false,
                'claims' => [['id' => 1]],
            ])
            ->assertStatus(403);
    }

    // ================================================================
    // 환불신청서 수동 접수 (D26)
    // ================================================================

    public function test_refund_request_files_the_refund_for_a_return_completed_without_one(): void
    {
        [, $return] = $this->collectedReturn();

        app(OrderReturnService::class)->completeReturn($return, [], new ClaimCompletionOptions(
            restoreStockByItem: [],
            createRefundRequest: false,
        ));

        $this->assertSame(0, OrderRefund::query()->where('order_id', $return->order_id)->count());

        $response = $this->actingAs($this->admin)
            ->postJson($this->base()."/returns/{$return->id}/refund-request");

        $response->assertStatus(201);

        $return->refresh();
        $this->assertNotNull($return->refund_id);
        $this->assertSame(26000.0, (float) OrderRefund::findOrFail($return->refund_id)->refund_amount);
    }

    public function test_refund_request_rejects_a_return_that_already_filed_one(): void
    {
        [, $return] = $this->collectedReturn();

        app(OrderReturnService::class)->completeReturn($return, [], new ClaimCompletionOptions(
            restoreStockByItem: [],
            createRefundRequest: true,
        ));

        $this->actingAs($this->admin)
            ->postJson($this->base()."/returns/{$return->fresh()->id}/refund-request")
            ->assertStatus(422);
    }

    public function test_refund_request_rejects_an_exchange_without_completed_items(): void
    {
        [, $exchange] = $this->sameOptionExchange();

        $service = app(OrderExchangeService::class);
        $exchange = $service->transition($exchange, ExchangeStatusEnum::APPROVED);

        // 완료된 상품이 없으면 접수할 것이 없다 — 접수 대상 판정은 완료 상품 기준이다.
        $this->actingAs($this->admin)
            ->postJson($this->base()."/exchanges/{$exchange->id}/refund-request")
            ->assertStatus(422);
    }

    // ================================================================
    // 금액 수정 (D18) — 교환·반품 축
    // ================================================================

    public function test_amounts_raises_the_zero_charge_return_with_a_charge_method(): void
    {
        $order = $this->deliveredClaimOrder();

        // 판매자 귀책 — 접수 시 청구 0원 (charge_method null).
        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'defective_product',
        ]);

        $this->assertNull($return->charge_method);

        $fired = [];
        HookManager::addAction('sirsoft-ecommerce.claim.return.amounts_updated', function ($claim, $before, $after) use (&$fired) {
            $fired[] = ['before' => $before, 'after' => $after];
        });

        // 청구 방식 없이 상향 — 결제할 수 없는 청구가 남으므로 거절 (S5 판정 13).
        $this->actingAs($this->admin)
            ->putJson($this->base()."/returns/{$return->id}/amounts", ['claim_shipping_fee' => 3000])
            ->assertStatus(422);

        // 청구 방식을 함께 실으면 통과한다.
        $this->actingAs($this->admin)
            ->putJson($this->base()."/returns/{$return->id}/amounts", [
                'claim_shipping_fee' => 3000,
                'charge_method' => ClaimChargeMethodEnum::PAY_ON_APPROVAL->value,
            ])
            ->assertStatus(200);

        $return->refresh();
        $this->assertSame(3000.0, (float) $return->claim_shipping_fee);
        $this->assertSame(3000.0, (float) $return->total_charge_amount);
        $this->assertSame(ClaimChargeMethodEnum::PAY_ON_APPROVAL, $return->charge_method);

        // 수정 전후 금액이 훅으로 나간다 — 활동 로그(S7)의 기록 근거 (D18).
        $this->assertCount(1, $fired);
        $this->assertSame(0.0, (float) $fired[0]['before']['claim_shipping_fee']);
        $this->assertSame(3000.0, (float) $fired[0]['after']['claim_shipping_fee']);
    }

    public function test_amounts_rejects_a_charge_method_outside_the_allowed_list(): void
    {
        $order = $this->deliveredClaimOrder();

        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'defective_product',
        ]);

        $this->setModuleSetting('claim.allowed_charge_methods', [ClaimChargeMethodEnum::DEDUCT_FROM_REFUND->value]);

        $this->actingAs($this->admin)
            ->putJson($this->base()."/returns/{$return->id}/amounts", [
                'claim_shipping_fee' => 3000,
                'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST->value,
            ])
            ->assertStatus(422);
    }

    public function test_amounts_rejects_a_final_claim(): void
    {
        [, $return] = $this->collectedReturn();

        app(OrderReturnService::class)->completeReturn($return, [], new ClaimCompletionOptions(
            restoreStockByItem: [],
            createRefundRequest: false,
        ));

        $this->actingAs($this->admin)
            ->putJson($this->base()."/returns/{$return->fresh()->id}/amounts", ['admin_memo' => 'memo'])
            ->assertStatus(422);
    }

    public function test_amounts_updates_the_exchange_round_trip_fee_and_totals(): void
    {
        [, $exchange] = $this->sameOptionExchange();

        $this->actingAs($this->admin)
            ->putJson($this->base()."/exchanges/{$exchange->id}/amounts", ['claim_shipping_fee' => 9000])
            ->assertStatus(200);

        $exchange->refresh();
        $this->assertSame(9000.0, (float) $exchange->claim_shipping_fee);
        $this->assertSame(9000.0, (float) $exchange->total_charge_amount);
        $this->assertTrue((bool) ($exchange->shipping_fee_snapshot['admin_adjusted'] ?? false));
    }

    /**
     * 모듈 설정 값을 지정합니다. (허용 청구 방식 게이트 픽스처)
     *
     * @param  string  $key  설정 키
     * @param  mixed  $value  값
     */
    protected function setModuleSetting(string $key, mixed $value): void
    {
        app(EcommerceSettingsService::class)->setSetting($key, $value);
    }

    // ================================================================
    // 3차 /validate-plan 보강 — 사유 구분 · 다건 스코프 · 권한 · 교차 유형 거절
    // ================================================================

    public function test_completion_preview_blocks_a_return_without_completable_items(): void
    {
        [, $return] = $this->collectedReturn();

        // 전건 반려 — 완료된 상품도, 완료 가능한 상품도 없다.
        $return->returnOptions()->update(['option_status' => ReturnStatusEnum::REJECTED->value]);

        $response = $this->actingAs($this->admin)
            ->postJson($this->base().'/claims/completion-preview', [
                'claim_type' => 'return',
                'create_refund_request' => true,
                'claims' => [['id' => $return->id]],
            ]);

        $response->assertStatus(200);

        // 사유 없이 applicable=true + 0원으로 답하면 모달은 '0원 접수' 를 예고하는데
        // 실제 접수는 422 다 — 교환 분기와 같은 두 사유 구분을 반품도 낸다.
        $this->assertFalse($response->json('data.claims.0.refund.applicable'));
        $this->assertSame(
            'claim_refund_not_applicable',
            $response->json('data.claims.0.refund.blocked_reason'),
        );
        $this->assertSame(0.0, (float) $response->json('data.claims.0.refund.refund_amount'));
    }

    public function test_completion_preview_reports_the_already_filed_reason_for_a_return(): void
    {
        [, $return] = $this->collectedReturn();

        app(OrderReturnService::class)->completeReturn($return, [], new ClaimCompletionOptions(
            restoreStockByItem: [],
            createRefundRequest: true,
        ));

        $response = $this->actingAs($this->admin)
            ->postJson($this->base().'/claims/completion-preview', [
                'claim_type' => 'return',
                'create_refund_request' => true,
                'claims' => [['id' => $return->fresh()->id]],
            ]);

        $response->assertStatus(200);
        $this->assertSame(
            'claim_refund_request_exists',
            $response->json('data.claims.0.refund.blocked_reason'),
        );
    }

    public function test_completion_preview_scopes_item_ids_per_claim_across_multiple_claims(): void
    {
        [, $first] = $this->collectedReturn();
        [, $second] = $this->collectedReturn();

        $firstItemId = $first->returnOptions->first()->id;
        $secondItemId = $second->returnOptions->first()->id;

        // 두 신청서를 한 요청에 실었을 때, 각 항목은 자기 부모 신청서 소속만 통과해야 한다.
        // 묶음 조회가 신청서 구분 없이 합집합으로 판정하면 아래 교차 배치가 통과한다.
        $this->actingAs($this->admin)
            ->postJson($this->base().'/claims/completion-preview', [
                'claim_type' => 'return',
                'create_refund_request' => false,
                'claims' => [
                    ['id' => $first->id, 'item_ids' => [$secondItemId]],
                    ['id' => $second->id, 'item_ids' => [$firstItemId]],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['claims.0.item_ids.0', 'claims.1.item_ids.0']);

        // 올바르게 배치하면 통과한다 — 게이트가 전부를 막는 것이 아님을 함께 고정한다.
        $this->actingAs($this->admin)
            ->postJson($this->base().'/claims/completion-preview', [
                'claim_type' => 'return',
                'create_refund_request' => false,
                'claims' => [
                    ['id' => $first->id, 'item_ids' => [$firstItemId]],
                    ['id' => $second->id, 'item_ids' => [$secondItemId]],
                ],
            ])
            ->assertStatus(200);
    }

    public function test_refund_request_requires_the_update_status_permission(): void
    {
        [, $return] = $this->collectedReturn();

        app(OrderReturnService::class)->completeReturn($return, [], new ClaimCompletionOptions(
            restoreStockByItem: [],
            createRefundRequest: false,
        ));

        // 부수효과(신청서 생성)가 있는 엔드포인트다 — 읽기 권한자는 막힌다.
        $reader = $this->createAdminUser(['sirsoft-ecommerce.claims.read']);

        $this->actingAs($reader)
            ->postJson($this->base()."/returns/{$return->fresh()->id}/refund-request")
            ->assertStatus(403);

        $this->assertNull($return->fresh()->refund_id);
    }

    public function test_amounts_prohibits_refund_fields_on_the_exchange_type(): void
    {
        [, $exchange] = $this->sameOptionExchange();

        // 교환·반품 축에 환불 3키를 보내면 거절한다 — 조용히 버리면 화면은 수정됐다고 믿는다.
        foreach (['refund_amount' => 1000, 'refund_points_amount' => 1000, 'refund_method' => 'pg'] as $field => $value) {
            $this->actingAs($this->admin)
                ->putJson($this->base()."/exchanges/{$exchange->id}/amounts", [$field => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }
    }

    public function test_amounts_prohibits_refund_fields_on_the_return_type(): void
    {
        [, $return] = $this->collectedReturn();

        $this->actingAs($this->admin)
            ->putJson($this->base()."/returns/{$return->id}/amounts", ['refund_amount' => 1000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('refund_amount');
    }

    public function test_amounts_rejects_an_unknown_refund_method(): void
    {
        [, $return] = $this->collectedReturn();

        app(OrderReturnService::class)->completeReturn($return, [], new ClaimCompletionOptions(
            restoreStockByItem: [],
            createRefundRequest: true,
        ));

        $refund = OrderRefund::findOrFail($return->fresh()->refund_id);

        $this->actingAs($this->admin)
            ->putJson($this->base()."/refunds/{$refund->id}/amounts", ['refund_method' => 'not_a_method'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('refund_method');
    }

    public function test_completion_preview_rejects_an_unsupported_claim_type(): void
    {
        // 완료 모달은 교환·반품 전용이다 — 환불·취소는 각자 다른 파이프라인이 소유한다.
        $this->actingAs($this->admin)
            ->postJson($this->base().'/claims/completion-preview', [
                'claim_type' => 'refund',
                'create_refund_request' => false,
                'claims' => [['id' => 1]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('claim_type');
    }

    public function test_completion_preview_rejects_an_empty_claim_list(): void
    {
        $this->actingAs($this->admin)
            ->postJson($this->base().'/claims/completion-preview', [
                'claim_type' => 'return',
                'create_refund_request' => false,
                'claims' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('claims');
    }

    // ================================================================
    // 완료 처리 미리보기 — 금액 표기 (S8b)
    //
    // 모달이 그리는 금액은 통화 기호·자릿수를 화면에서 조립할 수 없다 — 기본 통화가 원이
    // 아닌 상점에서 값은 맞고 단위만 틀린 금액이 나가는데, 오류도 경고도 남지 않는다.
    // 표기는 모듈의 통화 포맷터가 단일 출처이고, 표기 통화는 신청서(거래 시점)가 정한다.
    // 합계는 대상이 한 통화로 모일 때만 낸다.
    // ================================================================

    /**
     * @scenario claim_type=return, completion_choice=file_now
     *
     * @effects amounts_render_from_server_formatted_only
     */
    public function test_completion_preview_carries_formatted_amounts_from_the_currency_formatter(): void
    {
        [, $return] = $this->collectedReturn();
        $itemId = $return->returnOptions->first()->id;
        $formatter = app(CurrencyConversionService::class);

        $response = $this->actingAs($this->admin)
            ->postJson($this->base().'/claims/completion-preview', [
                'claim_type' => 'return',
                'create_refund_request' => true,
                'claims' => [[
                    'id' => $return->id,
                    'restore_stock_by_item' => [$itemId => true],
                ]],
            ]);

        $response->assertStatus(200);

        $row = $response->json('data.claims.0');
        $this->assertSame('KRW', $row['currency_code']);
        // 표기의 단일 출처는 모듈 포맷터다 — 서비스가 문자열을 손으로 조립하면 여기서 갈린다.
        $this->assertSame(
            $formatter->formatPrice(26000.0, 'KRW'),
            $row['refund']['refund_amount_formatted']
        );
        $this->assertSame(
            $formatter->formatPrice(0.0, 'KRW'),
            $row['refund']['refund_points_amount_formatted']
        );
        $this->assertSame(
            $formatter->formatPrice(3000.0, 'KRW'),
            $row['refund']['deducted_shipping_fee_formatted']
        );
        // 숫자만 내보내면 화면이 기호를 붙이게 된다 — 표기 문자열은 숫자와 달라야 한다.
        $this->assertNotSame('26000', $row['refund']['refund_amount_formatted']);

        // 대상이 한 통화로 모이면 합계도 표기한다.
        $this->assertSame('KRW', $response->json('data.totals.currency_code'));
        $this->assertSame(
            $formatter->formatPrice(26000.0, 'KRW'),
            $response->json('data.totals.refund_amount_total_formatted')
        );
    }

    /**
     * @scenario claim_type=exchange, completion_choice=file_now
     *
     * @effects amounts_render_from_server_formatted_only
     */
    public function test_completion_preview_formats_amounts_in_the_claim_currency_not_a_fixed_one(): void
    {
        // 거래 시점 통화가 원이 아닌 신청서 — 표기가 원으로 고정돼 있으면 여기서 드러난다.
        [, $return] = $this->collectedReturn();
        $return->forceFill(['currency_code' => 'USD'])->saveQuietly();

        $response = $this->actingAs($this->admin)
            ->postJson($this->base().'/claims/completion-preview', [
                'claim_type' => 'return',
                'create_refund_request' => true,
                'claims' => [['id' => $return->id]],
            ]);

        $response->assertStatus(200);

        $formatter = app(CurrencyConversionService::class);
        $this->assertSame('USD', $response->json('data.claims.0.currency_code'));
        $this->assertSame(
            $formatter->formatPrice(26000.0, 'USD'),
            $response->json('data.claims.0.refund.refund_amount_formatted')
        );
        $this->assertNotSame(
            $formatter->formatPrice(26000.0, 'KRW'),
            $response->json('data.claims.0.refund.refund_amount_formatted')
        );
    }

    /**
     * @scenario claim_type=return, completion_choice=file_later
     *
     * @effects completion_total_omitted_when_nothing_is_filed
     */
    public function test_completion_preview_omits_the_total_when_the_choice_files_nothing(): void
    {
        // '접수하지 않음' 이면 합계에 기여한 통화가 없다 — 0원을 특정 통화로 단정해 표기하지 않는다.
        [, $return] = $this->collectedReturn();

        $response = $this->actingAs($this->admin)
            ->postJson($this->base().'/claims/completion-preview', [
                'claim_type' => 'return',
                'create_refund_request' => false,
                'claims' => [['id' => $return->id]],
            ]);

        $response->assertStatus(200);

        $this->assertNull($response->json('data.totals.currency_code'));
        $this->assertNull($response->json('data.totals.refund_amount_total_formatted'));
    }

    /**
     * 통화가 섞이면 합계 표기를 내지 않는다 (§6-4-1 — 모달이 건수만 안내).
     *
     * 숫자를 더하면 값은 맞고 단위만 없는 금액이 되는데 화면에는 정상 합계로 보인다. 그래서
     * 서버가 `currency_code: null` 로 알리고 화면이 표기를 접는다. 원값 합계는 남긴다 —
     * 건수·차감 여부 같은 파생 판정이 그 값을 쓴다.
     *
     * @scenario claim_type=exchange, completion_choice=file_later
     *
     * @effects mixed_currency_total_falls_back_to_count_only
     */
    public function test_completion_preview_drops_total_formatting_when_currencies_are_mixed(): void
    {
        [, $krwReturn] = $this->collectedReturn();
        [, $usdReturn] = $this->collectedReturn();
        $usdReturn->forceFill(['currency_code' => 'USD'])->saveQuietly();

        $response = $this->actingAs($this->admin)
            ->postJson($this->base().'/claims/completion-preview', [
                'claim_type' => 'return',
                'create_refund_request' => true,
                'claims' => [['id' => $krwReturn->id], ['id' => $usdReturn->id]],
            ]);

        $response->assertStatus(200);

        // 행 단위 표기는 각자의 거래 시점 통화를 그대로 따른다.
        $currencies = collect($response->json('data.claims'))->pluck('currency_code')->sort()->values();
        $this->assertSame(['KRW', 'USD'], $currencies->all());

        // 합계 축만 접힌다 — 세 표기가 함께 비어야 화면이 한 곳만 보고 분기하지 않는다.
        $this->assertNull($response->json('data.totals.currency_code'));
        $this->assertNull($response->json('data.totals.refund_amount_total_formatted'));
        $this->assertNull($response->json('data.totals.deducted_shipping_fee_total_formatted'));

        // 원값은 유지된다 — 표기만 접는 것이지 계산을 버리는 것이 아니다.
        $this->assertGreaterThan(0, $response->json('data.totals.refund_amount_total'));
        $this->assertSame(2, $response->json('data.totals.claim_count'));
    }

    /**
     * 모달의 '함께 실행되는 처리' 가 회수배송비 차감을 알린다 (§6-4-1) — 그 값도 화면이
     * 조립하지 않고 응답의 표기 문자열을 그대로 그린다.
     *
     * @scenario claim_type=return, completion_choice=file_now
     *
     * @effects collection_fee_shown_in_completion_summary
     */
    public function test_completion_preview_totals_the_deducted_collection_fee(): void
    {
        [, $return] = $this->collectedReturn();
        $formatter = app(CurrencyConversionService::class);

        $response = $this->actingAs($this->admin)
            ->postJson($this->base().'/claims/completion-preview', [
                'claim_type' => 'return',
                'create_refund_request' => true,
                'claims' => [['id' => $return->id]],
            ]);

        $response->assertStatus(200);

        $this->assertSame(3000.0, (float) $response->json('data.totals.deducted_shipping_fee_total'));
        $this->assertSame(
            $formatter->formatPrice(3000.0, 'KRW'),
            $response->json('data.totals.deducted_shipping_fee_total_formatted')
        );
    }

    /**
     * 이번 라운드에 접수되지 않으면 그 차감도 이번에 일어나지 않는다 — 요약이 실행과
     * 갈리지 않도록 합계를 0 으로 두고 표기를 내지 않는다.
     *
     * @scenario claim_type=return, completion_choice=file_later
     *
     * @effects collection_fee_shown_in_completion_summary
     */
    public function test_completion_preview_omits_the_collection_fee_when_nothing_is_filed(): void
    {
        [, $return] = $this->collectedReturn();

        $response = $this->actingAs($this->admin)
            ->postJson($this->base().'/claims/completion-preview', [
                'claim_type' => 'return',
                'create_refund_request' => false,
                'claims' => [['id' => $return->id]],
            ]);

        $response->assertStatus(200);

        $this->assertSame(0.0, (float) $response->json('data.totals.deducted_shipping_fee_total'));
        $this->assertNull($response->json('data.totals.deducted_shipping_fee_total_formatted'));
    }
}
