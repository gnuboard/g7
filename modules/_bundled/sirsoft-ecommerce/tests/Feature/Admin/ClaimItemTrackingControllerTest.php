<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTrackingDirectionEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 상품 행 송장 API 테스트 (#599 후속 §3-8)
 *
 * 검증 축:
 * - `PATCH {type}/{id}/options/tracking`(「송장만 저장」) · `POST {type}/{id}/tracking/deliver`(운송장 단위 도착)
 *   · `PUT exchanges/{id}/exchange-mode`(진행 방식) × (200 · 422 도메인 · 422 검증 · 403 권한 · 404)
 * - 상세 봉투 `claim.tracking_groups` 모양 (운송장 묶음 · 송장 없이 도착 묶음 · [도착 처리] 가능 여부)
 * - 행 택배사 즉시 로드 — 행이 늘어도 택배사 조회 수가 늘지 않는다
 * - 헤더 송장 API(`collection-tracking`·`redelivery-tracking`)는 라우트가 없다
 */
class ClaimItemTrackingControllerTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected User $admin;

    /**
     * 클레임 조회·상태 변경 권한을 가진 운영자를 세웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.claims.read',
            'sirsoft-ecommerce.claims.update-status',
        ]);
    }

    /**
     * 통화 설정을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 관리자 API 의 공통 접두 경로를 돌려줍니다.
     *
     * @return string 관리자 라우트 접두 경로
     */
    protected function base(): string
    {
        return '/api/modules/sirsoft-ecommerce/admin';
    }

    /**
     * 승인된 반품을 만듭니다. (환불금 차감)
     *
     * @param  int  $quantity  신청 수량
     * @return OrderReturn 승인된 반품 신청서
     */
    protected function approvedReturn(int $quantity = 1): OrderReturn
    {
        $order = $this->deliveredClaimOrder(quantity: $quantity);
        $service = app(OrderReturnService::class);

        $return = $service->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => $quantity]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);

        return $service->transition($return, ReturnStatusEnum::APPROVED)->fresh(['returnOptions']);
    }

    /**
     * 왕복 배송비 0원 교환을 접수합니다. (승인 전)
     *
     * @return OrderExchange 접수된 교환 신청서
     */
    protected function requestedExchange(): OrderExchange
    {
        $order = $this->deliveredClaimOrder(exchangeFee: 0);

        return app(OrderExchangeService::class)->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
        ])->fresh(['exchangeOptions']);
    }

    /**
     * 신청서의 첫 상품 행 ID 를 돌려줍니다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @return int 상품 행 ID
     */
    protected function firstReturnItemId(OrderReturn $return): int
    {
        return (int) $return->fresh('returnOptions')->returnOptions->sortBy('id')->first()->id;
    }

    /**
     * 운영자가 등록한 택배사를 만듭니다.
     *
     * @param  string  $code  택배사 코드
     * @return ShippingCarrier 택배사
     */
    protected function operatorCarrier(string $code): ShippingCarrier
    {
        return ShippingCarrier::create([
            'code' => $code,
            'name' => ['ko' => '운영자 택배 '.$code, 'en' => 'Operator carrier '.$code],
            'type' => 'domestic',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    /**
     * 클레임 조회 권한만 가진 운영자를 만듭니다. (상태 변경 권한 없음)
     *
     * @return User 운영자
     */
    protected function readOnlyAdmin(): User
    {
        return $this->createAdminUser(['sirsoft-ecommerce.claims.read']);
    }

    // ================================================================
    // PATCH options/tracking — 「송장만 저장」
    // ================================================================

    /**
     * 「송장만 저장」 은 수량을 지정한 행을 쪼개 그 행에만 쓰고, 응답에 쪼갠 결과를 싣는다. 상태는 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, exchange_mode=standard, direction=collection
     *
     * @effects tracking_api_saves_rows_and_reports_the_split
     */
    public function test_tracking_only_save_splits_and_reports(): void
    {
        $return = $this->approvedReturn(3);
        $itemId = $this->firstReturnItemId($return);
        $carrier = $this->testClaimCarrier();

        $response = $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/options/tracking", [
            'direction' => 'collection',
            'carrier_id' => $carrier->id,
            'tracking_number' => 'API-1',
            'items' => [['id' => $itemId, 'quantity' => 1]],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.claim.status', 'approved')
            ->assertJsonPath('data.split.0.id', $itemId)
            ->assertJsonPath('data.split.0.quantity', 1)
            ->assertJsonPath('data.split.0.remainder_quantity', 2);

        $items = collect($response->json('data.claim.items'))->keyBy('id');
        $this->assertSame('API-1', $items[$itemId]['collection_tracking_number']);
        $this->assertNull($items[$response->json('data.split.0.remainder_id')]['collection_tracking_number']);
    }

    /**
     * 도메인 규칙 위반은 422 와 그 사유 문구다 — 반품의 재발송 방향 · 쓸 수 없는 상태 · 회수중 행의 송장 삭제.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, exchange_mode=standard, direction=redelivery
     *
     * @effects tracking_api_rejects_domain_violations_with_422
     */
    public function test_tracking_only_save_domain_violations_are_422(): void
    {
        $return = $this->approvedReturn();
        $itemId = $this->firstReturnItemId($return);
        $carrier = $this->testClaimCarrier();
        $url = $this->base()."/returns/{$return->id}/options/tracking";

        $this->actingAs($this->admin)->patchJson($url, [
            'direction' => 'redelivery',
            'carrier_id' => $carrier->id,
            'tracking_number' => 'RD-1',
            'items' => [['id' => $itemId]],
        ])->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_tracking_direction_not_allowed'));

        app(OrderReturnService::class)->transition($return->fresh(), ReturnStatusEnum::COLLECTING, [
            'carrier_id' => $carrier->id,
            'tracking_number' => 'MOVING-1',
        ]);

        $this->actingAs($this->admin)->patchJson($url, [
            'direction' => 'collection',
            'items' => [['id' => $itemId]],
        ])->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_tracking_required'));

        app(OrderReturnService::class)->transition($return->fresh(), ReturnStatusEnum::COLLECTED);

        $this->actingAs($this->admin)->patchJson($url, [
            'direction' => 'collection',
            'carrier_id' => $carrier->id,
            'tracking_number' => 'LATE-1',
            'items' => [['id' => $itemId]],
        ])->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_tracking_item_not_eligible'));

        $this->assertSame('MOVING-1', OrderReturnOption::find($itemId)->collection_tracking_number);
    }

    /**
     * 입력 형식 위반은 검증 422 다 — 방향 누락·어휘 밖 방향·51자 운송장·빈 대상·중복 행.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, exchange_mode=standard, direction=collection
     *
     * @effects tracking_api_validates_the_request_shape
     */
    public function test_tracking_only_save_validation_errors(): void
    {
        $return = $this->approvedReturn();
        $itemId = $this->firstReturnItemId($return);
        $carrier = $this->testClaimCarrier();
        $url = $this->base()."/returns/{$return->id}/options/tracking";

        $this->actingAs($this->admin)->patchJson($url, [
            'carrier_id' => $carrier->id, 'tracking_number' => 'V-1', 'items' => [['id' => $itemId]],
        ])->assertStatus(422)->assertJsonValidationErrors(['direction']);

        $this->actingAs($this->admin)->patchJson($url, [
            'direction' => 'sideways', 'carrier_id' => $carrier->id, 'tracking_number' => 'V-1', 'items' => [['id' => $itemId]],
        ])->assertStatus(422)->assertJsonValidationErrors(['direction']);

        $this->actingAs($this->admin)->patchJson($url, [
            'direction' => 'collection', 'carrier_id' => $carrier->id, 'tracking_number' => str_repeat('9', 51), 'items' => [['id' => $itemId]],
        ])->assertStatus(422)->assertJsonValidationErrors(['tracking_number']);

        $this->actingAs($this->admin)->patchJson($url, [
            'direction' => 'collection', 'carrier_id' => $carrier->id, 'tracking_number' => 'V-1', 'items' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['items']);

        $this->actingAs($this->admin)->patchJson($url, [
            'direction' => 'collection', 'carrier_id' => $carrier->id, 'tracking_number' => 'V-1', 'items' => [['id' => $itemId], ['id' => $itemId]],
        ])->assertStatus(422)->assertJsonValidationErrors(['items']);

        $this->assertNull(OrderReturnOption::find($itemId)->collection_tracking_number);
    }

    /**
     * 상태 변경 권한이 없으면 403, 다른 신청서의 행 id 는 404 다(그 행이 있다는 사실을 드러내지 않는다).
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, exchange_mode=standard, direction=collection
     *
     * @effects tracking_api_guards_permission_and_foreign_rows
     */
    public function test_tracking_only_save_forbidden_and_foreign_row(): void
    {
        $return = $this->approvedReturn();
        $other = $this->approvedReturn();
        $carrier = $this->testClaimCarrier();
        $url = $this->base()."/returns/{$return->id}/options/tracking";

        $this->actingAs($this->readOnlyAdmin())->patchJson($url, [
            'direction' => 'collection', 'carrier_id' => $carrier->id, 'tracking_number' => 'P-1',
            'items' => [['id' => $this->firstReturnItemId($return)]],
        ])->assertForbidden();

        $this->actingAs($this->admin)->patchJson($url, [
            'direction' => 'collection', 'carrier_id' => $carrier->id, 'tracking_number' => 'P-1',
            'items' => [['id' => $this->firstReturnItemId($other)]],
        ])->assertNotFound();

        $this->actingAs($this->admin)->patchJson($this->base().'/returns/999999/options/tracking', [
            'direction' => 'collection', 'carrier_id' => $carrier->id, 'tracking_number' => 'P-1',
            'items' => [['id' => $this->firstReturnItemId($return)]],
        ])->assertNotFound();

        $this->assertNull(OrderReturnOption::find($this->firstReturnItemId($other))->collection_tracking_number);
    }

    // ================================================================
    // POST tracking/deliver — 운송장 단위 도착
    // ================================================================

    /**
     * [도착 처리] 는 그 운송장의 행 전부를 회수도착으로 옮기고 처리 결과를 싣는다. 없는 운송장은 422 다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, exchange_mode=standard, direction=collection
     *
     * @effects tracking_deliver_api_moves_the_waybill_rows
     */
    public function test_deliver_moves_the_waybill_rows_and_rejects_unknown_waybills(): void
    {
        $return = $this->approvedReturn(2);
        $itemId = $this->firstReturnItemId($return);
        $carrier = $this->testClaimCarrier();
        app(OrderReturnService::class)->writeItemTracking($return, ClaimTrackingDirectionEnum::COLLECTION, [$itemId], $carrier->id, 'DLV-1');
        $url = $this->base()."/returns/{$return->id}/tracking/deliver";

        $this->actingAs($this->admin)->postJson($url, [
            'direction' => 'collection', 'carrier_id' => $carrier->id, 'tracking_number' => 'DLV-1',
        ])->assertOk()
            ->assertJsonPath('data.succeeded.0', $itemId)
            ->assertJsonPath('data.skipped', [])
            ->assertJsonPath('data.claim.status', 'collected');

        $this->actingAs($this->admin)->postJson($url, [
            'direction' => 'collection', 'carrier_id' => $carrier->id, 'tracking_number' => 'NOPE-1',
        ])->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_tracking_not_found'));
    }

    /**
     * [도착 처리] 입력은 방향·택배사·운송장이 모두 있어야 하고, 권한이 없으면 403, 없는 신청서는 404 다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, exchange_mode=standard, direction=collection
     *
     * @effects tracking_deliver_api_validates_and_guards
     */
    public function test_deliver_validation_permission_and_missing_claim(): void
    {
        $return = $this->approvedReturn();
        $carrier = $this->testClaimCarrier();
        $url = $this->base()."/returns/{$return->id}/tracking/deliver";

        $this->actingAs($this->admin)->postJson($url, ['direction' => 'collection'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['carrier_id', 'tracking_number']);

        $this->actingAs($this->readOnlyAdmin())->postJson($url, [
            'direction' => 'collection', 'carrier_id' => $carrier->id, 'tracking_number' => 'D-1',
        ])->assertForbidden();

        $this->actingAs($this->admin)->postJson($this->base().'/returns/999999/tracking/deliver', [
            'direction' => 'collection', 'carrier_id' => $carrier->id, 'tracking_number' => 'D-1',
        ])->assertNotFound();

        // 환불에는 회수 축이 없다 — 송장 라우트가 없다.
        $this->actingAs($this->admin)->postJson($this->base().'/refunds/1/tracking/deliver', [
            'direction' => 'collection', 'carrier_id' => $carrier->id, 'tracking_number' => 'D-1',
        ])->assertNotFound();
    }

    // ================================================================
    // PUT exchanges/{id}/exchange-mode — 진행 방식
    // ================================================================

    /**
     * 선교환 전환은 설정이 켜져 있으면 200 이고 봉투가 진행 방식을 싣는다. 설정이 꺼져 있으면 422 도메인 거절이다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, exchange_mode=advance, direction=redelivery
     *
     * @effects exchange_mode_api_switches_and_rejects_when_disabled
     */
    public function test_exchange_mode_switch_and_disabled_rejection(): void
    {
        $exchange = $this->requestedExchange();
        $url = $this->base()."/exchanges/{$exchange->id}/exchange-mode";

        $this->actingAs($this->admin)->putJson($url, ['mode' => 'advance'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_advance_exchange_disabled'));

        app(EcommerceSettingsService::class)->setSetting('claim.advance_exchange_enabled', true);

        $this->actingAs($this->admin)->putJson($url, ['mode' => 'advance'])
            ->assertOk()
            ->assertJsonPath('data.claim.exchange_mode', 'advance');

        $fresh = $exchange->fresh();
        $this->assertSame(ExchangeModeEnum::ADVANCE, $fresh->mode());
        $this->assertSame((int) $this->admin->id, (int) $fresh->exchange_mode_set_by);
        $this->assertNotNull($fresh->exchange_mode_set_at);

        $this->actingAs($this->admin)->getJson($this->base()."/exchanges/{$exchange->id}")
            ->assertOk()
            ->assertJsonPath('data.exchange_mode_abilities.can_set_standard', true)
            ->assertJsonPath('data.exchange_mode_abilities.can_set_advance', false);
    }

    /**
     * 전환 확인 창이 안내하는 반송 기한 일수는 실제 기한 계산과 같은 출처다 — 한계(1~90일) 밖 값이 저장돼
     * 있으면 서비스가 기본값(14일)으로 기한을 잡으므로 안내도 14일이다(validate branch D8). 한계 안 값은 그대로 싣는다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, exchange_mode=advance, direction=collection
     *
     * @effects exchange_mode_abilities_due_days_follow_the_service_bounds
     */
    public function test_exchange_mode_abilities_due_days_follow_the_service_bounds(): void
    {
        $exchange = $this->requestedExchange();
        $settings = app(EcommerceSettingsService::class);
        $detail = fn () => $this->actingAs($this->admin)->getJson($this->base()."/exchanges/{$exchange->id}")->assertOk();

        $settings->setSetting('claim.advance_exchange_collection_due_days', 500);
        $detail()->assertJsonPath('data.exchange_mode_abilities.collection_due_days', 14);

        $settings->setSetting('claim.advance_exchange_collection_due_days', 30);
        $detail()->assertJsonPath('data.exchange_mode_abilities.collection_due_days', 30);
    }

    /**
     * 진행 방식 입력 검증 422 · 권한 403 · 없는 교환 404 · 반품에는 진행 방식 라우트가 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, exchange_mode=standard, direction=collection
     *
     * @effects exchange_mode_api_validates_and_guards
     */
    public function test_exchange_mode_validation_permission_and_missing_claim(): void
    {
        $exchange = $this->requestedExchange();
        $return = $this->approvedReturn();
        app(EcommerceSettingsService::class)->setSetting('claim.advance_exchange_enabled', true);
        $url = $this->base()."/exchanges/{$exchange->id}/exchange-mode";

        $this->actingAs($this->admin)->putJson($url, ['mode' => 'turbo'])
            ->assertStatus(422)->assertJsonValidationErrors(['mode']);
        $this->actingAs($this->admin)->putJson($url, [])
            ->assertStatus(422)->assertJsonValidationErrors(['mode']);

        $this->actingAs($this->readOnlyAdmin())->putJson($url, ['mode' => 'advance'])->assertForbidden();

        $this->actingAs($this->admin)->putJson($this->base().'/exchanges/999999/exchange-mode', ['mode' => 'advance'])
            ->assertNotFound();
        $this->actingAs($this->admin)->putJson($this->base()."/returns/{$return->id}/exchange-mode", ['mode' => 'advance'])
            ->assertNotFound();

        $this->assertSame(ExchangeModeEnum::STANDARD, $exchange->fresh()->mode());
    }

    // ================================================================
    // 상세 봉투 · 즉시 로드 · 삭제된 헤더 송장 API
    // ================================================================

    /**
     * 상세 봉투의 `tracking_groups` 는 운송장별 묶음이다 — 같은 운송장 행이 한 묶음, 송장 없이 도착한 행은
     * 「송장 없이 도착」 묶음, 반품은 재발송 묶음이 비어 있다. [도착 처리] 가능 여부는 관리자에게만 싣는다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, exchange_mode=standard, direction=collection
     *
     * @effects detail_envelope_groups_rows_by_waybill
     */
    public function test_detail_envelope_groups_rows_by_waybill(): void
    {
        $return = $this->approvedReturn(3);
        $service = app(OrderReturnService::class);
        $carrier = $this->testClaimCarrier();
        $itemId = $this->firstReturnItemId($return);

        // 1개 + 1개는 같은 상자(BOX-G), 나머지 1개는 송장 없이 고객이 직접 가져왔다(회수도착).
        $second = $service->writeItemTracking($return, ClaimTrackingDirectionEnum::COLLECTION, [['id' => $itemId, 'quantity' => 1]], $carrier->id, 'BOX-G')['split'][0]['remainder_id'];
        $third = $service->writeItemTracking($return->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [['id' => $second, 'quantity' => 1]], $carrier->id, 'BOX-G')['split'][0]['remainder_id'];
        $service->writeItemTracking($return->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [$third], null, null);
        $service->transitionOption($return->fresh(['returnOptions']), [$third], ReturnStatusEnum::COLLECTED);

        $response = $this->actingAs($this->admin)->getJson($this->base()."/returns/{$return->id}")->assertOk();

        $groups = $response->json('data.claim.tracking_groups');
        $this->assertSame([], $groups['redelivery']);
        $this->assertCount(2, $groups['collection']);

        [$waybill, $noTracking] = $groups['collection'];
        $this->assertFalse($waybill['no_tracking']);
        $this->assertSame('BOX-G', $waybill['tracking_number']);
        $this->assertSame($carrier->id, $waybill['carrier_id']);
        $this->assertNotNull($waybill['carrier_name']);
        $this->assertSame([$itemId, $second], collect($waybill['items'])->pluck('claim_item_id')->sort()->values()->all());
        $this->assertFalse($waybill['all_arrived']);
        $this->assertTrue($waybill['can_deliver']);
        $this->assertArrayHasKey('tracking_url', $waybill);
        $this->assertSame(['claim_item_id', 'product_name', 'option_name', 'quantity', 'status_label', 'arrived'], array_keys($waybill['items'][0]));

        $this->assertTrue($noTracking['no_tracking']);
        $this->assertSame([$third], collect($noTracking['items'])->pluck('claim_item_id')->all());
        $this->assertTrue($noTracking['all_arrived']);
        $this->assertFalse($noTracking['can_deliver']);
    }

    /**
     * 상세는 행 택배사를 즉시 로드한다 — 행과 택배사가 늘어도 택배사 테이블 조회 수가 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, exchange_mode=standard, direction=collection
     *
     * @effects detail_eager_loads_row_carriers
     */
    public function test_detail_eager_loads_row_carriers(): void
    {
        $single = $this->approvedReturn();
        app(OrderReturnService::class)->writeItemTracking($single, ClaimTrackingDirectionEnum::COLLECTION, [$this->firstReturnItemId($single)], $this->operatorCarrier('eager_0')->id, 'E-0');

        $many = $this->approvedReturn(3);
        $service = app(OrderReturnService::class);
        $itemId = $this->firstReturnItemId($many);
        $second = $service->writeItemTracking($many, ClaimTrackingDirectionEnum::COLLECTION, [['id' => $itemId, 'quantity' => 1]], $this->operatorCarrier('eager_1')->id, 'E-1')['split'][0]['remainder_id'];
        $third = $service->writeItemTracking($many->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [['id' => $second, 'quantity' => 1]], $this->operatorCarrier('eager_2')->id, 'E-2')['split'][0]['remainder_id'];
        $service->writeItemTracking($many->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [$third], $this->operatorCarrier('eager_3')->id, 'E-3');

        $carrierQueries = function (int $returnId): int {
            $count = 0;
            DB::listen(function ($query) use (&$count) {
                if (str_contains($query->sql, 'shipping_carriers')) {
                    $count++;
                }
            });

            $this->actingAs($this->admin)->getJson($this->base()."/returns/{$returnId}")->assertOk();

            return $count;
        };

        $one = $carrierQueries($single->id);
        $three = $carrierQueries($many->id);

        $this->assertLessThanOrEqual($one, $three, "행 3개 상세의 택배사 조회가 {$three}회로, 행 1개({$one}회)보다 많습니다 — 행 택배사 N+1.");
    }

    /**
     * 헤더 송장 저장 API 는 삭제됐다 — 호환 경로 없이 404 다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, exchange_mode=standard, direction=redelivery
     *
     * @effects header_tracking_apis_are_removed
     */
    public function test_removed_header_tracking_apis_are_404(): void
    {
        $return = $this->approvedReturn();
        $exchange = $this->requestedExchange();
        $carrier = $this->testClaimCarrier();
        $body = ['carrier_id' => $carrier->id, 'tracking_number' => 'OLD-1'];

        $this->actingAs($this->admin)->putJson($this->base()."/returns/{$return->id}/collection-tracking", $body)->assertNotFound();
        $this->actingAs($this->admin)->putJson($this->base()."/exchanges/{$exchange->id}/collection-tracking", $body)->assertNotFound();
        $this->actingAs($this->admin)->putJson($this->base()."/exchanges/{$exchange->id}/redelivery-tracking", $body)->assertNotFound();

        $this->assertSame(ExchangeStatusEnum::REQUESTED, $exchange->fresh()->exchange_status);
    }
}
