<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 클레임 상세 화면 API Feature 테스트 (S9a)
 *
 * 상세 화면(`/admin/ecommerce/claims/{claimNumber}`)이 서는 두 엔드포인트를 고정한다:
 *
 * - `GET admin/claims/{claimNumber}` — 번호 기반 상세. 신청서 외에 화면이 필요로 하는 넷
 *   (경로 세그먼트·능력·완료 기본값·진행단계·택배사)을 같은 봉투로 나른다. 그 넷을 각자의
 *   엔드포인트에서 읽으면 게이트가 이 화면(`claims.read`)보다 좁아, 클레임 권한만 가진
 *   운영자에게만 값이 조용히 빈다 — 전권 계정에서는 재현되지 않는 결함이다.
 * - `GET admin/claims/{claimNumber}/logs` — 신청서 + 그 신청서의 추가결제·첨부 로그 합본.
 *
 * 진행단계는 화면이 순서를 다시 적지 않도록 서버가 만든다 — 결제 두 칸은 청구가 있는
 * 건에서만 지나가고, 보류·반려·철회는 순서 밖의 이탈 칸이다.
 */
class ClaimDetailScreenTest extends ModuleTestCase
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
     * 반품 신청서를 서비스 경로로 접수합니다. (환불금 차감 — 청구 없음)
     */
    protected function makeReturn(?Order $order = null): OrderReturn
    {
        $order ??= $this->deliveredClaimOrder();

        return app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);
    }

    /**
     * 교환 신청서를 서비스 경로로 접수합니다. (승인 후 결제 — 왕복 배송비 청구)
     */
    protected function makeExchange(
        ?Order $order = null,
        ClaimChargeMethodEnum $chargeMethod = ClaimChargeMethodEnum::PAY_ON_APPROVAL,
    ): OrderExchange {
        $order ??= $this->deliveredClaimOrder();

        return app(OrderExchangeService::class)->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
            'charge_method' => $chargeMethod,
        ]);
    }

    // ================================================================
    // 번호 기반 상세
    // ================================================================

    /**
     * @scenario claim_type=exchange, claim_stage=in_progress
     *
     * @effects claim_detail_response_carries_screen_context, claim_detail_never_assembles_path_segment
     */
    public function test_detail_by_number_resolves_type_and_carries_screen_context(): void
    {
        $this->ensureCarrier();
        $exchange = $this->makeExchange();

        $response = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$exchange->exchange_number);

        $response->assertOk()
            ->assertJsonPath('data.claim.claim_number', $exchange->exchange_number)
            ->assertJsonPath('data.claim.claim_type', 'exchange')
            // 화면이 `claim_type + 's'` 로 조립하지 않도록 서버가 세그먼트를 준다.
            ->assertJsonPath('data.claim_path_segment', 'exchanges')
            ->assertJsonPath('data.abilities.can_update_status', true)
            ->assertJsonPath('data.abilities.can_update_amounts', false)
            ->assertJsonPath('data.complete_defaults.restore_stock', true)
            ->assertJsonPath('data.complete_defaults.create_refund_request', true);

        // 상세 응답은 관리자 확장 필드와 결제 블록을 함께 싣는다 (목록 행은 축소 표현이다).
        $this->assertArrayHasKey('admin_memo', $response->json('data.claim'));
        $this->assertArrayHasKey('payments', $response->json('data.claim'));
        $this->assertNotEmpty($response->json('data.carriers'));
    }

    /**
     * @scenario claim_type=return, claim_stage=in_progress
     *
     * @effects claim_detail_completion_seeds_settings_defaults, claim_detail_datasources_within_route_permission
     */
    public function test_detail_by_number_carries_settings_defaults_to_claim_only_operator(): void
    {
        // 설정 엔드포인트는 `settings.read` 게이트다 — 클레임 권한만 가진 운영자에게는 403 이라
        // 상세가 그 값을 실어 나르지 않으면 상점이 꺼 둔 기본값이 계약 기본값으로 되돌아간다.
        app(EcommerceSettingsService::class)->setSetting('claim.complete_defaults', [
            'restore_stock' => false,
            'create_refund_request' => false,
        ]);

        $return = $this->makeReturn();

        $this->actingAs($this->admin)->getJson($this->base().'/settings')->assertForbidden();

        $this->actingAs($this->admin)->getJson($this->base().'/claims/'.$return->return_number)
            ->assertOk()
            ->assertJsonPath('data.complete_defaults.restore_stock', false)
            ->assertJsonPath('data.complete_defaults.create_refund_request', false);
    }

    /**
     * @scenario claim_type=refund, claim_stage=in_progress
     *
     * @effects claim_carrier_options_come_from_detail_response, claim_shipping_section_absent_for_refund
     */
    public function test_detail_by_number_omits_carriers_for_refund(): void
    {
        $this->ensureCarrier();
        $return = $this->moveReturnToCompleted($this->makeReturn());
        $refund = $return->refresh()->refund;

        $this->assertNotNull($refund, '반품 완료가 환불신청서를 만들지 않았다');

        $this->actingAs($this->admin)->getJson($this->base().'/claims/'.$refund->refund_number)
            ->assertOk()
            ->assertJsonPath('data.claim_path_segment', 'refunds')
            // 환불에는 회수·재발송 축이 없다 — 그리지 않는 값을 싣지 않는다.
            ->assertJsonPath('data.carriers', [])
            ->assertJsonPath('data.redelivery_address_differs', null);
    }

    /**
     * @scenario claim_type=exchange, claim_stage=in_progress
     *
     * @effects detail_unknown_number_shows_error_page
     */
    public function test_detail_by_number_returns_404_for_unknown_number(): void
    {
        $this->actingAs($this->admin)->getJson($this->base().'/claims/NOPE00000000')
            ->assertNotFound();
    }

    /**
     * @scenario claim_type=return, claim_stage=in_progress
     *
     * @effects detail_controls_gated_by_write_abilities
     */
    public function test_detail_by_number_requires_read_permission(): void
    {
        $return = $this->makeReturn();
        $outsider = $this->createAdminUser([]);

        $this->actingAs($outsider)->getJson($this->base().'/claims/'.$return->return_number)
            ->assertForbidden();
    }

    /**
     * @scenario claim_type=return, claim_stage=in_progress
     *
     * @effects claim_detail_route_registered
     */
    public function test_static_claim_routes_are_not_shadowed_by_the_number_wildcard(): void
    {
        // 와일드카드가 정적 경로보다 먼저 잡히면 목록이 "번호 NOPE" 조회가 되어 404 가 된다.
        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=return')->assertOk();
    }

    // ================================================================
    // 진행 단계 (스텝퍼)
    // ================================================================

    /**
     * @scenario claim_type=return, claim_stage=in_progress
     *
     * @effects claim_progress_steps_come_from_server, claim_progress_payment_stages_follow_charge_method
     */
    public function test_progress_steps_drop_payment_stages_when_nothing_is_charged(): void
    {
        $return = $this->makeReturn();

        $steps = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$return->return_number)
            ->assertOk()
            ->json('data.progress_steps');

        $values = array_column($steps, 'value');

        $this->assertSame(
            ['requested', 'approved', 'collection_ready', 'collecting', 'collected', 'completed'],
            $values,
        );
        $this->assertSame('current', $steps[0]['state']);
        $this->assertSame('upcoming', $steps[1]['state']);
    }

    /**
     * @scenario claim_type=exchange, claim_stage=in_progress
     *
     * @effects claim_progress_payment_stages_follow_charge_method
     */
    public function test_progress_steps_keep_payment_stages_when_a_charge_exists(): void
    {
        $exchange = $this->makeExchange();

        $this->assertGreaterThan(0, (float) $exchange->total_charge_amount, '교환 청구액이 0 이라 축이 성립하지 않는다');

        $values = array_column(
            $this->actingAs($this->admin)
                ->getJson($this->base().'/claims/'.$exchange->exchange_number)
                ->assertOk()
                ->json('data.progress_steps'),
            'value',
        );

        $this->assertContains('payment_pending', $values);
        $this->assertContains('paid', $values);
        $this->assertSame('completed', end($values));
    }

    /**
     * @scenario claim_type=return, claim_stage=in_progress
     *
     * @effects claim_progress_stepper_renders_server_steps
     */
    public function test_progress_steps_mark_reached_stages_and_the_current_one(): void
    {
        $return = $this->makeReturn();
        app(OrderReturnService::class)->transition($return, ReturnStatusEnum::APPROVED);

        $steps = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$return->return_number)
            ->assertOk()
            ->json('data.progress_steps');

        $byValue = array_column($steps, 'state', 'value');

        $this->assertSame('done', $byValue['requested']);
        $this->assertSame('current', $byValue['approved']);
        $this->assertSame('upcoming', $byValue['collecting']);
        $this->assertCount(1, array_filter($steps, fn ($step) => $step['state'] === 'current'));
    }

    /**
     * @scenario claim_type=return, claim_stage=off_path
     *
     * @effects claim_progress_off_path_stage_appended
     */
    public function test_progress_steps_append_an_off_path_stage_for_on_hold(): void
    {
        // 보류는 승인 전에만 진입한다 (승인 뒤는 회수 절차라 되돌릴 칸이 없다).
        $return = $this->makeReturn();
        app(OrderReturnService::class)->transition($return, ReturnStatusEnum::ON_HOLD);

        $steps = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$return->return_number)
            ->assertOk()
            ->json('data.progress_steps');

        $last = end($steps);

        // 보류는 흐름을 따라가는 단계가 아니라 멈추는 사건이다 — 순서 밖 칸으로 붙는다.
        $this->assertSame('on_hold', $last['value']);
        $this->assertSame('off_path', $last['state']);
        // 접수는 실제로 지나갔으므로 그 칸은 지나간 것으로 그린다.
        $this->assertSame('done', array_column($steps, 'state', 'value')['requested']);
        // 이탈 상태에서는 순서 안에 '현재' 칸이 없다.
        $this->assertCount(0, array_filter($steps, fn ($step) => $step['state'] === 'current'));
    }

    /**
     * 청구 방식·금액이 바뀌어도 신청서가 서 있는 칸은 사라지지 않는다.
     *
     * 결제 두 칸은 결제형 청구에서만 실리는데(D17 진행단계), 그 판정은 **지금** 값으로 한다 —
     * 운영자가 결제 대기 상태에서 배송비를 0 으로 내리면 청구가 사라지면서 현재 상태가 순서에서
     * 빠진다. 그러면 도달 인덱스가 접혀 신청서는 결제 대기인데 스텝퍼는 '신청 접수' 를 현재로
     * 그린다 — 오류도 경고도 남지 않아 운영자는 그것을 사실로 읽는다.
     *
     * @scenario claim_type=exchange, claim_stage=in_progress
     *
     * @effects claim_progress_payment_stages_follow_charge_method, claim_progress_stepper_renders_server_steps
     */
    public function test_progress_steps_keep_the_stage_the_claim_stands_on_after_the_charge_is_removed(): void
    {
        // 신청 시점 결제 — 결제 요청이 나가면 결제 대기로 선다.
        $exchange = $this->makeExchange(chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST);
        app(AdminClaimService::class)->requestPayment($exchange, $this->admin->id);
        $exchange->refresh();

        $this->assertSame(
            ExchangeStatusEnum::PAYMENT_PENDING,
            $exchange->exchange_status,
            '접수 직후 결제 대기가 아니면 이 축이 성립하지 않는다',
        );

        // 운영자가 청구를 없앤다 (왕복 배송비 면제) — `PUT amounts` 가 받는 축이다.
        app(AdminClaimService::class)->updateAmounts($exchange, ['claim_shipping_fee' => 0], $this->admin->id);
        $exchange->refresh();

        $this->assertFalse($exchange->requiresAdditionalPayment(), '청구가 남아 있으면 이 축이 성립하지 않는다');

        $steps = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$exchange->exchange_number)
            ->assertOk()
            ->json('data.progress_steps');

        $byValue = array_column($steps, 'state', 'value');

        // 신청서가 실제로 서 있는 칸이 순서에 남아 있고, 그 칸이 현재다.
        $this->assertArrayHasKey('payment_pending', $byValue, '현재 상태의 칸이 순서에서 사라졌다');
        $this->assertSame('current', $byValue['payment_pending']);
        $this->assertSame('done', $byValue['requested']);
        $this->assertCount(1, array_filter($steps, fn ($step) => $step['state'] === 'current'));
    }

    /**
     * @scenario claim_type=return, claim_stage=off_path
     *
     * @effects claim_progress_off_path_stage_appended
     */
    public function test_progress_steps_use_stamps_to_place_an_off_path_claim(): void
    {
        // 승인 뒤 반려된 건은 현재 상태만으로는 어디까지 갔는지 알 수 없다 — 신청서가 박아 둔
        // 승인 시각이 그 단계를 실제로 지났다는 증거다.
        $return = $this->makeReturn();
        app(OrderReturnService::class)->transition($return, ReturnStatusEnum::APPROVED);
        $return->refresh()->forceFill([
            'return_status' => ReturnStatusEnum::REJECTED,
            'rejected_at' => now(),
        ])->save();

        $steps = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$return->return_number)
            ->assertOk()
            ->json('data.progress_steps');

        $byValue = array_column($steps, 'state', 'value');

        $this->assertSame('done', $byValue['approved']);
        $this->assertSame('upcoming', $byValue['collecting']);
        $this->assertSame('off_path', end($steps)['state']);
    }

    /**
     * @scenario claim_type=return, claim_stage=final
     *
     * @effects claim_progress_stepper_renders_server_steps
     */
    public function test_progress_steps_mark_a_completed_return_as_done(): void
    {
        $return = $this->moveReturnToCompleted($this->makeReturn());

        $steps = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$return->return_number)
            ->assertOk()
            ->json('data.progress_steps');

        $last = end($steps);

        $this->assertSame('completed', $last['value']);
        $this->assertSame('current', $last['state']);
        // 종결 건에는 아직 지나가지 않은 칸이 없다.
        $this->assertCount(0, array_filter($steps, fn ($step) => $step['state'] === 'upcoming'));
    }

    /**
     * @scenario claim_type=refund, claim_stage=off_path
     *
     * @effects claim_progress_off_path_stage_appended
     */
    public function test_progress_steps_append_an_off_path_stage_for_a_rejected_refund(): void
    {
        $return = $this->moveReturnToCompleted($this->makeReturn());
        $refund = $return->refresh()->refund;

        $this->assertNotNull($refund, '반품 완료가 환불신청서를 만들지 않았다');

        $refund->forceFill(['refund_status' => RefundStatusEnum::REJECTED])->save();

        $steps = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$refund->refund_number)
            ->assertOk()
            ->json('data.progress_steps');

        $last = end($steps);

        $this->assertSame('rejected', $last['value']);
        $this->assertSame('off_path', $last['state']);
        // 환불은 회수·재발송 단계가 없고 결제 단계도 없다 — 순서는 넷뿐이다.
        $this->assertCount(5, $steps);
    }

    // ================================================================
    // 활동 로그
    // ================================================================

    /**
     * @scenario claim_type=exchange, claim_stage=in_progress
     *
     * @effects claim_logs_merge_claim_payment_and_attachment
     */
    public function test_logs_merge_claim_payment_and_attachment_entries(): void
    {
        $exchange = $this->makeExchange();

        // 추가결제·첨부의 로그는 신청서가 아니라 그 자식에 붙는다 (`ClaimActivityLogListener`) —
        // 운영자에게는 셋이 같은 신청서의 이력이므로 한 목록으로 합쳐 보인다.
        $payment = ClaimPayment::create([
            'payable_type' => ClaimTypeEnum::EXCHANGE->value,
            'payable_id' => $exchange->id,
            'order_id' => $exchange->order_id,
            'payment_number' => 'CP-LOGS-1',
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'amount' => 3000,
            'points_amount' => 0,
            'total_amount' => 3000,
            'currency_code' => 'KRW',
        ]);

        $image = ClaimImage::factory()->create([
            'claimable_type' => ClaimTypeEnum::EXCHANGE->value,
            'claimable_id' => $exchange->id,
        ]);

        $this->logFor(ClaimPayment::class, $payment->id, 'claim_payment.complete');
        $this->logFor(ClaimImage::class, $image->id, 'claim_image.upload');

        $rows = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$exchange->exchange_number.'/logs')
            ->assertOk()
            ->json('data.data');

        $actions = array_column($rows, 'action');

        $this->assertContains('claim_exchange.request', $actions, '신청서 자신의 로그가 빠졌다');
        $this->assertContains('claim_payment.complete', $actions, '추가결제 로그가 빠졌다');
        $this->assertContains('claim_image.upload', $actions, '첨부 로그가 빠졌다');
    }

    /**
     * @scenario claim_type=exchange, claim_stage=in_progress
     *
     * @effects claim_logs_scoped_to_one_claim
     */
    public function test_logs_do_not_leak_another_claims_payment_entries(): void
    {
        $mine = $this->makeExchange();
        $other = $this->makeExchange($this->deliveredClaimOrder());

        $otherPayment = ClaimPayment::create([
            'payable_type' => ClaimTypeEnum::EXCHANGE->value,
            'payable_id' => $other->id,
            'order_id' => $other->order_id,
            'payment_number' => 'CP-LOGS-2',
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'amount' => 3000,
            'points_amount' => 0,
            'total_amount' => 3000,
            'currency_code' => 'KRW',
        ]);

        $this->logFor(ClaimPayment::class, $otherPayment->id, 'claim_payment.complete');

        $actions = array_column(
            $this->actingAs($this->admin)
                ->getJson($this->base().'/claims/'.$mine->exchange_number.'/logs')
                ->assertOk()
                ->json('data.data'),
            'action',
        );

        $this->assertNotContains('claim_payment.complete', $actions, '다른 신청서의 결제 로그가 섞였다');
    }

    /**
     * @scenario claim_type=return, claim_stage=in_progress
     *
     * @effects claim_logs_scoped_to_one_claim
     */
    public function test_logs_do_not_leak_another_claims_entries(): void
    {
        $first = $this->makeReturn();
        $second = $this->makeReturn($this->deliveredClaimOrder());

        $rows = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$first->return_number.'/logs')
            ->assertOk()
            ->json('data.data');

        foreach ($rows as $row) {
            $this->assertNotSame(
                $second->id,
                $row['loggable_id'],
                '다른 신청서의 로그가 섞였다',
            );
        }
        $this->assertNotEmpty($rows);
    }

    /**
     * 활동 로그 한 줄을 심습니다. (합본 축 검증용 — 리스너 경로와 같은 형태)
     *
     * @param  string  $loggableType  loggable 타입 (추가결제·첨부는 morph map 밖이라 FQCN)
     * @param  int  $loggableId  loggable ID
     * @param  string  $action  action 키
     */
    protected function logFor(string $loggableType, int $loggableId, string $action): void
    {
        ActivityLog::create([
            'log_type' => 'admin',
            'loggable_type' => $loggableType,
            'loggable_id' => $loggableId,
            'action' => $action,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.claim_payment_complete',
            'description_params' => [],
            'properties' => [],
        ]);
    }

    /**
     * @scenario claim_type=return, claim_stage=in_progress
     *
     * @effects claim_logs_honour_sort_and_page_size
     */
    public function test_logs_honour_sort_and_page_size(): void
    {
        $return = $this->makeReturn();
        app(OrderReturnService::class)->transition($return, ReturnStatusEnum::APPROVED);

        $response = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$return->return_number.'/logs?per_page=1&sort_order=asc');

        $response->assertOk()->assertJsonCount(1, 'data.data');
        $this->assertSame(1, $response->json('data.meta.per_page') ?? $response->json('data.pagination.per_page'));
    }

    /**
     * @scenario claim_type=return, claim_stage=in_progress
     *
     * @effects claim_logs_reject_out_of_vocabulary_sort
     */
    public function test_logs_reject_an_out_of_vocabulary_sort_order(): void
    {
        $return = $this->makeReturn();

        $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$return->return_number.'/logs?sort_order=sideways')
            ->assertStatus(422);
    }

    /**
     * @scenario claim_type=return, claim_stage=in_progress
     *
     * @effects detail_unknown_number_shows_error_page
     */
    public function test_logs_return_404_for_unknown_number(): void
    {
        $this->actingAs($this->admin)->getJson($this->base().'/claims/NOPE00000000/logs')
            ->assertNotFound();
    }

    // ================================================================
    // 재발송지 뱃지 근거 (D14)
    // ================================================================

    /**
     * @scenario claim_type=exchange, claim_stage=in_progress
     *
     * @effects claim_redelivery_two_badges_distinct
     */
    public function test_redelivery_address_differs_is_false_when_the_snapshot_matches_the_order(): void
    {
        $exchange = $this->makeExchange();

        $this->actingAs($this->admin)->getJson($this->base().'/claims/'.$exchange->exchange_number)
            ->assertOk()
            ->assertJsonPath('data.redelivery_address_differs', false);
    }

    /**
     * @scenario claim_type=exchange, claim_stage=in_progress
     *
     * @effects claim_redelivery_address_differs_badge_alone
     */
    public function test_redelivery_address_differs_is_true_when_the_snapshot_diverges(): void
    {
        $exchange = $this->makeExchange();

        $snapshot = $exchange->redelivery_address_snapshot;
        $snapshot['address2'] = '다른 상세주소';
        $exchange->forceFill(['redelivery_address_snapshot' => $snapshot])->save();

        $this->actingAs($this->admin)->getJson($this->base().'/claims/'.$exchange->exchange_number)
            ->assertOk()
            // 두 뱃지는 서로 다른 사실을 말한다 — 값이 달라도 고객이 손댄 것은 아니다.
            ->assertJsonPath('data.redelivery_address_differs', true)
            ->assertJsonPath('data.claim.redelivery_address_snapshot.changed_by_user', false);
    }

    /**
     * 반품 신청서를 완료까지 진행시킵니다. (환불신청서 생성 경로)
     */
    protected function moveReturnToCompleted(OrderReturn $return): OrderReturn
    {
        $service = app(OrderReturnService::class);
        $return = $service->transition($return, ReturnStatusEnum::APPROVED);
        $return = $service->transition($return->fresh(), ReturnStatusEnum::COLLECTION_READY);
        $return = $service->transition($return->fresh(), ReturnStatusEnum::COLLECTING);
        $return = $service->transition($return->fresh(), ReturnStatusEnum::COLLECTED);

        return app(AdminClaimService::class)->complete(
            $return->fresh(),
            [],
            new ClaimCompletionOptions(restoreStockByItem: [], createRefundRequest: true),
        );
    }

    /**
     * 활성 택배사 한 건을 확보합니다. (형제 테스트와 같은 생성 경로 — 팩토리가 없다)
     */
    protected function ensureCarrier(): ShippingCarrier
    {
        return ShippingCarrier::first() ?? ShippingCarrier::create([
            'code' => 'test-carrier',
            'name' => ['ko' => '테스트택배', 'en' => 'Test Carrier'],
            'is_active' => true,
        ]);
    }
}
