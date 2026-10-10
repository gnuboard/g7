<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Extension\HookManager;
use App\Models\ActivityLog;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\CancelOptionStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderCancelOption;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\ReadsReturnRefundLink;
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
    use ReadsReturnRefundLink;

    protected User $admin;

    /**
     * 테스트 통화·사유와 클레임 읽기·상태 변경 권한만 가진 운영자를 준비합니다.
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
     * 테스트 통화 설정을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 관리자 API 기준 경로를 반환합니다.
     *
     * @return string 관리자 API 기준 경로
     */
    protected function base(): string
    {
        return '/api/modules/sirsoft-ecommerce/admin';
    }

    /**
     * 반품 신청서를 서비스 경로로 접수합니다. (환불금 차감 — 청구 없음)
     *
     * @param  Order|null  $order  대상 주문 (없으면 배송완료 주문을 새로 만든다)
     * @return OrderReturn 접수된 반품 신청서
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
     *
     * @param  Order|null  $order  대상 주문 (없으면 배송완료 주문을 새로 만든다)
     * @param  ClaimChargeMethodEnum  $chargeMethod  청구 방식
     * @return OrderExchange 접수된 교환 신청서
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
     * 클레임 번호로 상세를 열면 유형을 해석하고 화면 문맥(경로 세그먼트·택배사 등)을 함께 싣는지 검증합니다.
     *
     * @scenario claim_type=exchange, claim_stage=in_progress
     *
     * @effects claim_detail_response_carries_screen_context, claim_detail_never_assembles_path_segment
     *
     * @return void 반환값 없음
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
     * 클레임 권한만 가진 운영자에게도 완료 처리 기본값이 상세 응답으로 전달되는지 검증합니다.
     *
     * @scenario claim_type=return, claim_stage=in_progress
     *
     * @effects claim_detail_completion_seeds_settings_defaults, claim_detail_datasources_within_route_permission
     *
     * @return void 반환값 없음
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
     * 환불신청서 상세에는 배송 축(택배사 선택지·재발송지 비교)이 실리지 않는지 검증합니다.
     *
     * @scenario claim_type=refund, claim_stage=in_progress
     *
     * @effects claim_carrier_options_come_from_detail_response, claim_shipping_section_absent_for_refund
     *
     * @return void 반환값 없음
     */
    public function test_detail_by_number_omits_carriers_for_refund(): void
    {
        $this->ensureCarrier();
        $return = $this->moveReturnToCompleted($this->makeReturn());
        $refund = $this->returnRefund($return);

        $this->assertNotNull($refund, '반품 완료가 환불신청서를 만들지 않았다');

        $this->actingAs($this->admin)->getJson($this->base().'/claims/'.$refund->refund_number)
            ->assertOk()
            ->assertJsonPath('data.claim_path_segment', 'refunds')
            // 환불에는 회수·재발송 축이 없다 — 그리지 않는 값을 싣지 않는다.
            ->assertJsonPath('data.carriers', [])
            ->assertJsonPath('data.redelivery_address_differs', null);
    }

    /**
     * 없는 클레임 번호의 상세 요청이 404 로 끝나는지 검증합니다.
     *
     * @scenario claim_type=exchange, claim_stage=in_progress
     *
     * @effects detail_unknown_number_shows_error_page
     *
     * @return void 반환값 없음
     */
    public function test_detail_by_number_returns_404_for_unknown_number(): void
    {
        $this->actingAs($this->admin)->getJson($this->base().'/claims/NOPE00000000')
            ->assertNotFound();
    }

    /**
     * 클레임 읽기 권한이 없는 운영자의 상세 요청이 403 으로 거절되는지 검증합니다.
     *
     * @scenario claim_type=return, claim_stage=in_progress
     *
     * @effects detail_controls_gated_by_write_abilities
     *
     * @return void 반환값 없음
     */
    public function test_detail_by_number_requires_read_permission(): void
    {
        $return = $this->makeReturn();
        $outsider = $this->createAdminUser([]);

        $this->actingAs($outsider)->getJson($this->base().'/claims/'.$return->return_number)
            ->assertForbidden();
    }

    /**
     * 고정 경로(목록·건수 등)가 클레임 번호 와일드카드 라우트에 가려지지 않는지 검증합니다.
     *
     * @scenario claim_type=return, claim_stage=in_progress
     *
     * @effects claim_detail_route_registered
     *
     * @return void 반환값 없음
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
     * 청구가 없는 신청서의 진행단계에서 결제 두 칸이 빠지는지 검증합니다.
     *
     * @scenario claim_type=return, claim_stage=in_progress
     *
     * @effects claim_progress_steps_come_from_server, claim_progress_payment_stages_follow_charge_method
     *
     * @return void 반환값 없음
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
     * 결제형 청구가 있는 신청서의 진행단계에 결제 두 칸이 남는지 검증합니다.
     *
     * @scenario claim_type=exchange, claim_stage=in_progress
     *
     * @effects claim_progress_payment_stages_follow_charge_method
     *
     * @return void 반환값 없음
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
     * 진행단계가 지난 칸과 현재 칸(한 칸)을 표시하는지 검증합니다.
     *
     * @scenario claim_type=return, claim_stage=in_progress
     *
     * @effects claim_progress_stepper_renders_server_steps
     *
     * @return void 반환값 없음
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
     * 보류된 신청서의 진행단계 끝에 순서 밖 이탈 칸이 붙는지 검증합니다.
     *
     * @scenario claim_type=return, claim_stage=off_path
     *
     * @effects claim_progress_off_path_stage_appended
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     * 이탈 상태 신청서의 도달 지점을 상태 시각 도장으로 정하는지 검증합니다.
     *
     * @scenario claim_type=return, claim_stage=off_path
     *
     * @effects claim_progress_off_path_stage_appended
     *
     * @return void 반환값 없음
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
     * 완료된 반품의 진행단계가 마지막 칸까지 완료로 표시되는지 검증합니다.
     *
     * @scenario claim_type=return, claim_stage=final
     *
     * @effects claim_progress_stepper_renders_server_steps
     *
     * @return void 반환값 없음
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
    /**
     * 칸마다 아이콘·색이 실린다 — 화면이 상태 값으로 그림을 다시 고르면 그 표가 어휘의
     * 두 번째 출처가 된다. 값은 상태 Enum 의 `icon()`/`variant()` 와 같아야 뱃지와 그림이 맞는다.
     *
     * @scenario claim_type=exchange, claim_stage=in_progress
     *
     * @effects claim_progress_steps_carry_icon_and_variant
     *
     * @return void 반환값 없음
     */
    public function test_progress_steps_carry_the_icon_and_variant_of_each_status(): void
    {
        $exchange = $this->makeExchange();

        $steps = $this->actingAs($this->admin)
            ->getJson($this->base()."/claims/{$exchange->exchange_number}")
            ->assertOk()
            ->json('data.progress_steps');

        $this->assertNotEmpty($steps);

        foreach ($steps as $step) {
            $case = ExchangeStatusEnum::from($step['value']);
            $this->assertSame($case->icon(), $step['icon']);
            $this->assertSame($case->variant(), $step['variant']);
        }

        $this->assertSame('status-request', $steps[0]['icon']);
        $this->assertSame('status-completed', end($steps)['icon']);
    }

    /**
     * 반려된 환불신청서의 진행단계 끝에 순서 밖 이탈 칸(반려)이 붙는지 검증합니다.
     *
     * @scenario claim_type=refund, claim_stage=off_path
     *
     * @effects claim_progress_off_path_stage_appended
     *
     * @return void 반환값 없음
     */
    public function test_progress_steps_append_an_off_path_stage_for_a_rejected_refund(): void
    {
        $return = $this->moveReturnToCompleted($this->makeReturn());
        $refund = $this->returnRefund($return);

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
     * 활동 로그가 신청서·추가결제·첨부의 기록을 한 목록으로 합쳐 싣는지 검증합니다.
     *
     * @scenario claim_type=exchange, claim_stage=in_progress
     *
     * @effects claim_logs_merge_claim_payment_and_attachment
     *
     * @return void 반환값 없음
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
     * 다른 신청서의 추가결제 로그가 이 신청서의 활동 로그에 섞이지 않는지 검증합니다.
     *
     * @scenario claim_type=exchange, claim_stage=in_progress
     *
     * @effects claim_logs_scoped_to_one_claim
     *
     * @return void 반환값 없음
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
     * 다른 신청서의 로그가 이 신청서의 활동 로그에 섞이지 않는지 검증합니다.
     *
     * @scenario claim_type=return, claim_stage=in_progress
     *
     * @effects claim_logs_scoped_to_one_claim
     *
     * @return void 반환값 없음
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
     * @param  int  $loggableId  loggable 대상 ID
     * @param  string  $action  로그 action 키
     * @return void 반환값 없음
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
     * 활동 로그가 정렬 방향과 페이지 크기 요청을 따르는지 검증합니다.
     *
     * @scenario claim_type=return, claim_stage=in_progress
     *
     * @effects claim_logs_honour_sort_and_page_size
     *
     * @return void 반환값 없음
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
     * 어휘 밖 정렬 값의 활동 로그 요청이 422 로 거절되는지 검증합니다.
     *
     * @scenario claim_type=return, claim_stage=in_progress
     *
     * @effects claim_logs_reject_out_of_vocabulary_sort
     *
     * @return void 반환값 없음
     */
    public function test_logs_reject_an_out_of_vocabulary_sort_order(): void
    {
        $return = $this->makeReturn();

        $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$return->return_number.'/logs?sort_order=sideways')
            ->assertStatus(422);
    }

    /**
     * 없는 클레임 번호의 활동 로그 요청이 404 로 끝나는지 검증합니다.
     *
     * @scenario claim_type=return, claim_stage=in_progress
     *
     * @effects detail_unknown_number_shows_error_page
     *
     * @return void 반환값 없음
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
     * 재발송지 스냅샷이 주문 배송지와 같으면 「배송지 다름」이 false 인지 검증합니다.
     *
     * @scenario claim_type=exchange, claim_stage=in_progress
     *
     * @effects claim_redelivery_two_badges_distinct
     *
     * @return void 반환값 없음
     */
    public function test_redelivery_address_differs_is_false_when_the_snapshot_matches_the_order(): void
    {
        $exchange = $this->makeExchange();

        $this->actingAs($this->admin)->getJson($this->base().'/claims/'.$exchange->exchange_number)
            ->assertOk()
            ->assertJsonPath('data.redelivery_address_differs', false);
    }

    /**
     * 재발송지 스냅샷이 주문 배송지와 다르면 「배송지 다름」이 true 인지 검증합니다.
     *
     * @scenario claim_type=exchange, claim_stage=in_progress
     *
     * @effects claim_redelivery_address_differs_badge_alone
     *
     * @return void 반환값 없음
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
     *
     * @param  OrderReturn  $return  반품 신청서
     * @return OrderReturn 완료된 반품 신청서
     */
    protected function moveReturnToCompleted(OrderReturn $return): OrderReturn
    {
        $service = app(OrderReturnService::class);
        $return = $service->transition($return, ReturnStatusEnum::APPROVED);
        $return = $service->transition($return->fresh(), ReturnStatusEnum::COLLECTION_READY);
        $return = $service->transition($return->fresh(), ReturnStatusEnum::COLLECTING, $this->claimTrackingFor(ReturnStatusEnum::COLLECTING));
        $return = $service->transition($return->fresh(), ReturnStatusEnum::COLLECTED);

        return app(AdminClaimService::class)->complete(
            $return->fresh(),
            [],
            new ClaimCompletionOptions(restoreStockByItem: [], createRefundRequest: true),
        );
    }

    // ================================================================
    // 결제 정보 · 정산 · 환불 실행 봉투 (S9b — D17-③·D18·D19)
    // ================================================================

    /**
     * 주문 상세 엔드포인트는 `orders.read` 게이트다 — 결제 정보 섹션이 그것을 직접 부르면
     * 클레임 권한만 가진 운영자에게만 403 이 되어 탭이 통째로 빈다. 봉투가 통로다.
     *
     * @scenario claim_type=exchange, cancellable=absent
     *
     * @effects claim_payment_reads_detail_envelope_only
     *
     * @return void 반환값 없음
     */
    public function test_detail_carries_order_payment_context_to_a_claim_only_operator(): void
    {
        $exchange = $this->makeExchange();

        // 주문 상세는 이 운영자에게 닫혀 있다.
        $this->actingAs($this->admin)
            ->getJson($this->base().'/orders/'.$exchange->order_id)
            ->assertForbidden();

        $response = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$exchange->exchange_number)
            ->assertOk();

        $order = $response->json('data.order');

        $this->assertIsArray($order);
        $this->assertNotEmpty($order['payments'], '결제수단 카드가 그릴 결제행이 없다');
        // 금액은 서버가 표기한다 — 화면이 기호를 붙이면 기본 통화가 원이 아닌 상점에서
        // 값은 맞고 단위만 틀린 금액이 나간다.
        foreach ([
            'subtotal_amount_formatted',
            'total_shipping_amount_formatted',
            'total_paid_amount_formatted',
            'total_tax_amount_formatted',
            'total_vat_amount_formatted',
        ] as $key) {
            $this->assertArrayHasKey($key, $order);
        }
    }

    /**
     * 이 화면은 주문 옵션·배송·주소를 그리지 않는다 — 그리지 않는 하위 컬렉션을 실으면
     * 상세 한 번을 여는 것만으로 응답이 부풀고, 미로드 관계가 직렬화 시점에 터진다.
     *
     * @effects claim_payment_order_context_is_pruned
     *
     * @return void 반환값 없음
     */
    public function test_detail_order_context_carries_only_what_the_payment_section_draws(): void
    {
        $return = $this->makeReturn();

        $order = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$return->return_number)
            ->assertOk()
            ->json('data.order');

        foreach (['options', 'shippings', 'shipping_address', 'billing_address', 'cancels', 'user'] as $key) {
            $this->assertArrayNotHasKey($key, $order, "결제 정보 섹션이 그리지 않는 `{$key}` 가 실렸다");
        }
    }

    /**
     * 환불 예정액은 헤더 컬럼이 아니라 완료 시점 재계산 결과다 — 완료 처리 모달과 **같은**
     * 경로에서 나와야 상세와 모달이 같은 신청서에 대해 다른 금액을 말하지 않는다.
     *
     * @scenario claim_type=return, cancellable=absent
     *
     * @effects claim_settlement_preview_comes_from_server
     *
     * @return void 반환값 없음
     */
    public function test_detail_settlement_matches_the_completion_preview(): void
    {
        $return = $this->makeReturn();

        $settlement = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$return->return_number)
            ->assertOk()
            ->json('data.settlement');

        $preview = app(OrderReturnService::class)->previewCompletion($return->refresh());

        $this->assertNotNull($settlement);
        $this->assertSame((float) $preview['refund_amount'], (float) $settlement['refund_amount']);
        $this->assertSame($preview['blocked_reason'], $settlement['blocked_reason']);
        $this->assertArrayHasKey('refund_amount_formatted', $settlement);
    }

    /**
     * 교환 정산도 완료 처리 모달과 같은 숫자여야 한다.
     *
     * 완료 미리보기는 '지금 완료 처리하면' 을 전제로 완료 가정 상품을 넘긴다. 상세가 그
     * 인자를 빼고 부르면 아직 완료된 상품이 없는 교환에서 후보가 통째로 비어 환불 예정액이
     * 0 원 + '환불 대상 없음' 으로 나가는데, 같은 신청서의 완료 모달은 실제 차액을 예고한다.
     * 값이 그럴듯해 오류가 남지 않으므로 두 화면을 나란히 놓기 전에는 드러나지 않는다.
     *
     * @scenario claim_type=exchange, cancellable=absent
     *
     * @effects claim_settlement_preview_comes_from_server
     *
     * @return void 반환값 없음
     */
    public function test_detail_settlement_matches_the_completion_preview_for_an_exchange(): void
    {
        [, $exchange] = $this->redeliveredLowerPricedExchange();

        $this->assertGreaterThan(
            0,
            (float) $exchange->refund_product_amount,
            '저가 교환의 동결 차액이 이 픽스처의 전제다.',
        );

        $settlement = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$exchange->exchange_number)
            ->assertOk()
            ->json('data.settlement');

        $preview = $this->actingAs($this->admin)
            ->postJson($this->base().'/claims/completion-preview', [
                'claim_type' => 'exchange',
                'create_refund_request' => true,
                'claims' => [['id' => $exchange->id]],
            ])
            ->assertOk()
            ->json('data.claims.0.refund');

        $this->assertNotNull($settlement);
        $this->assertSame((float) $preview['refund_amount'], (float) $settlement['refund_amount']);
        $this->assertSame((float) $preview['refund_points_amount'], (float) $settlement['refund_points_amount']);
        $this->assertSame((float) $preview['deducted_shipping_fee'], (float) $settlement['deducted_shipping_fee']);
        $this->assertSame($preview['blocked_reason'], $settlement['blocked_reason']);
    }

    /**
     * 환불신청서는 자기 금액이 헤더 컬럼이라 이 블록이 필요 없다 — 실으면 화면이 같은 값을
     * 두 자리에서 다르게 그린다.
     *
     * @scenario claim_type=refund, cancellable=present
     *
     * @effects claim_settlement_blocks_split_by_type
     *
     * @return void 반환값 없음
     */
    public function test_detail_settlement_is_null_for_a_refund_request(): void
    {
        $return = $this->moveReturnToCompleted($this->makeReturn());
        $refund = $this->returnRefund($return);

        $this->assertNotNull($refund, '반품 완료가 환불신청서를 만들지 않았다');

        $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$refund->refund_number)
            ->assertOk()
            ->assertJsonPath('data.settlement', null)
            ->assertJsonPath('data.charge_methods', []);
    }

    /**
     * 허용 어휘는 운영자 설정이 정하고 그 조회는 `settings.read` 게이트다 — 화면이 셋을 다시
     * 적으면 상점이 막아 둔 방식이 선택 가능해지고 저장이 422 가 된다.
     *
     * @scenario claim_type=exchange, cancellable=absent
     *
     * @effects claim_charge_method_options_come_from_detail_response
     *
     * @return void 반환값 없음
     */
    public function test_detail_charge_methods_honour_the_shop_allow_list(): void
    {
        // 신청은 기본 허용 목록으로 접수하고, 그 뒤 상점이 목록을 좁힌다 — 운영 중 설정을
        // 바꾸는 것이 이 축의 실제 모습이며 접수된 신청서는 그대로 남는다.
        $exchange = $this->makeExchange();

        app(EcommerceSettingsService::class)->setSetting('claim.allowed_charge_methods', [
            ClaimChargeMethodEnum::DEDUCT_FROM_REFUND->value,
        ]);

        $options = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$exchange->exchange_number)
            ->assertOk()
            ->json('data.charge_methods');

        $this->assertSame(['deduct_from_refund'], array_column($options, 'value'));
        $this->assertNotEmpty($options[0]['label']);
    }

    /**
     * 환불 수단 어휘는 Enum 이 소유한다 — 화면이 셋을 다시 적으면 수단이 늘어도 화면만
     * 낡은 채로 남는다. 다른 유형에는 그 축이 없다.
     *
     * @scenario claim_type=refund, cancellable=present
     *
     * @effects claim_refund_method_options_come_from_detail_response
     *
     * @return void 반환값 없음
     */
    public function test_detail_refund_methods_are_present_only_for_a_refund_request(): void
    {
        // PG 환불에 응답할 리스너를 둔다 — 없으면 이 카드 주문은 실행이 계좌 환불로 떨어져(P14)
        // 선택지에서 PG 가 빠진다(저장·실행 게이트와 같은 판정 — #599 validate P2-A 후속).
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn (array $default) => $default);
        // 「마일리지로 환불」은 회원 주문이고 마일리지 기능이 켜진 상점에서만 선택지에 선다(Q21 — 저장·실행
        // 게이트와 같은 술어 pointsRefundAllowed). 세 수단이 모두 서는 조건을 명시한다.
        app(EcommerceSettingsService::class)->setSetting('mileage.enabled', true);

        $return = $this->moveReturnToCompleted($this->makeReturn());
        $refund = $this->returnRefund($return);

        $methods = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$refund->refund_number)
            ->assertOk()
            ->json('data.refund_methods');

        $this->assertSame(RefundMethodEnum::values(), array_column($methods, 'value'));

        $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$return->refresh()->return_number)
            ->assertOk()
            ->assertJsonPath('data.refund_methods', []);
    }

    /**
     * 수단별 잔여의 라벨은 결제수단 카탈로그가 정한다.
     *
     * PG 플러그인이 등록하는 확장 수단(`nhnkcp_naverpay` 등)은 `PaymentMethodEnum` 에 case 가
     * 없다 — enum 만 보고 해석하면 원시 ID 가 화면에 그대로 나간다. 같은 화면 위쪽 결제 카드는
     * 카탈로그를 거쳐 '네이버페이' 를 그리므로, 한 화면의 두 자리가 같은 수단을 다른 말로
     * 부르게 된다(값이 있어 오류는 남지 않는다).
     *
     * @scenario claim_type=refund, cancellable=present
     *
     * @effects claim_cancellable_method_label_from_catalog
     *
     * @return void 반환값 없음
     */
    public function test_detail_cancellable_method_label_comes_from_the_payment_method_catalog(): void
    {
        HookManager::addFilter(
            'sirsoft-ecommerce.settings.filter_available_payment_methods',
            fn (array $methods) => array_merge($methods, [[
                'id' => 'nhnkcp_naverpay',
                'name' => ['ko' => '네이버페이', 'en' => 'Naver Pay'],
                'description' => ['ko' => '', 'en' => ''],
                'icon' => 'credit-card',
                'source' => 'plugin:sirsoft-pay_nhnkcp',
                'defaults' => [
                    'pg_provider' => 'nhnkcp',
                    'pg_locked' => true,
                    'needs_pg' => true,
                    'refund_method' => 'pg',
                    'is_active' => true,
                ],
            ]])
        );

        $return = $this->moveReturnToCompleted($this->makeReturn());
        $refund = $this->returnRefund($return);

        // 확장 수단으로 결제된 주문 — payment_method 에 enum 캐스트가 없는 이유가 이것이다.
        $refund->order->payments()->update(['payment_method' => 'nhnkcp_naverpay']);

        $methods = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$refund->refund_number)
            ->assertOk()
            ->json('data.cancellable.methods');

        $payment = collect($methods)->firstWhere('method', 'nhnkcp_naverpay');

        $this->assertNotNull($payment, '확장 수단 결제행이 수단별 잔여에 실려야 이 축이 성립한다.');
        $this->assertSame(
            app(PaymentMethodResolver::class)->label('nhnkcp_naverpay'),
            $payment['method_label'],
            '수단 라벨의 SSoT 는 결제수단 카탈로그다 — enum 폴백만 보면 원시 ID 가 나간다.',
        );
        $this->assertNotSame('nhnkcp_naverpay', $payment['method_label']);
    }

    /**
     * 취소 가능 금액의 표기 자릿수는 거래 시점 스냅샷이 정한다.
     *
     * 운영자가 그 통화의 자릿수를 바꾸거나 통화를 지우면 현재 설정만 읽는 표기는 함께
     * 움직인다 — 과거 주문의 금액 표기가 조용히 달라진다. 금액 계산은 스냅샷을 쓰는데
     * 표기만 현재 설정을 따라가면 같은 화면 안에서 근거가 갈린다.
     *
     * @scenario claim_type=refund, cancellable=present
     *
     * @effects claim_cancellable_formatting_uses_the_order_currency_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_detail_cancellable_formatting_honours_the_order_currency_snapshot(): void
    {
        $return = $this->moveReturnToCompleted($this->makeReturn());
        $refund = $this->returnRefund($return);
        $order = $refund->order;

        // 거래 시점에 2자리로 박제된 통화. 현재 설정의 그 통화는 0자리(반올림 단위 1)라,
        // 스냅샷을 읽지 않으면 소수점이 사라져 과거 주문의 표기가 조용히 바뀐다.
        $code = $order->currency_code ?: 'KRW';
        $order->forceFill([
            'currency_snapshot' => [
                'base_currency' => $code,
                'exchange_rates' => [$code => ['rate' => 1, 'decimal_places' => 2]],
            ],
        ])->save();

        $formatted = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$refund->refund_number)
            ->assertOk()
            ->json('data.cancellable.amount_formatted');

        $this->assertNotNull($formatted);
        $this->assertStringContainsString(
            '.',
            (string) $formatted,
            '박제된 자릿수가 표기에 반영되지 않았다 — 스냅샷이 아니라 현재 설정을 읽고 있다.',
        );
    }

    /**
     * 취소 가능 금액은 성격이 다른 두 상한의 최솟값이고, 진행 중 클레임 예정액은 한도를
     * 깎지 않는 참고 축이다 (D19). 최종 금액만으로는 왜 그 금액인지 알 수 없고, 한도를
     * 정한 축에 따라 운영자의 조치가 갈리므로 세 값이 모두 응답에 실린다.
     *
     * @scenario claim_type=refund, cancellable=present
     *
     * @effects claim_cancellable_axes_come_from_server
     *
     * @return void 반환값 없음
     */
    public function test_detail_carries_the_cancellable_amount_with_its_three_axes(): void
    {
        $return = $this->moveReturnToCompleted($this->makeReturn());
        $refund = $this->returnRefund($return);

        $cancellable = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$refund->refund_number)
            ->assertOk()
            ->json('data.cancellable');

        $this->assertIsArray($cancellable);
        foreach ([
            'amount', 'amount_formatted',
            'domain_refundable', 'domain_refundable_formatted',
            'payment_capacity', 'payment_capacity_formatted',
            'reserved', 'reserved_formatted',
            'binding', 'binding_label', 'methods', 'reserved_claims',
        ] as $key) {
            $this->assertArrayHasKey($key, $cancellable);
        }

        // 라벨 키는 백엔드 네임스페이스라 레이아웃이 해석할 수 없다 — 표시 문자열이 함께 온다.
        $this->assertNotSame($cancellable['binding_label_key'], $cancellable['binding_label']);

        // 이 문맥은 그 환불신청서 자신을 선점 집계에서 뺀다 — 빼지 않으면 자기 금액만큼
        // 한도가 줄어 실행이 언제나 상한 초과가 된다.
        $this->assertSame(
            [],
            array_values(array_filter(
                $cancellable['reserved_claims'],
                fn (array $entry) => (int) $entry['id'] === (int) $refund->id && $entry['type'] === 'refund'
            )),
        );
    }

    /**
     * 다른 유형에는 환불 실행 축이 없다 — 실으면 화면이 그리지 않는 산정을 매 상세 조회마다
     * 돌린다.
     *
     * @scenario claim_type=exchange, cancellable=absent
     *
     * @effects claim_refund_execute_section_absent_for_non_refund
     *
     * @return void 반환값 없음
     */
    public function test_detail_omits_the_cancellable_amount_for_non_refund_types(): void
    {
        $exchange = $this->makeExchange();

        $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$exchange->exchange_number)
            ->assertOk()
            ->assertJsonPath('data.cancellable', null);
    }

    /**
     * 상세 봉투와 형제 엔드포인트가 같은 지점에서 표기를 만든다 — 갈리면 같은 산정이 두
     * 화면에서 다른 문자열로 나간다.
     *
     * @scenario claim_type=refund, cancellable=present
     *
     * @effects claim_cancellable_payload_is_identical_across_endpoints
     *
     * @return void 반환값 없음
     */
    public function test_cancellable_endpoint_and_detail_envelope_return_the_same_payload(): void
    {
        $return = $this->moveReturnToCompleted($this->makeReturn());
        $refund = $this->returnRefund($return);

        $fromDetail = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$refund->refund_number)
            ->assertOk()
            ->json('data.cancellable');

        $fromEndpoint = $this->actingAs($this->admin)
            ->getJson($this->base().'/orders/'.$refund->order_id.'/cancellable-amount?refund_id='.$refund->id)
            ->assertOk()
            ->json('data.cancellable');

        $this->assertSame($fromDetail, $fromEndpoint);
    }

    /**
     * 활성 택배사 한 건을 확보합니다. (형제 테스트와 같은 생성 경로 — 팩토리가 없다)
     *
     * @return ShippingCarrier 택배사
     */
    protected function ensureCarrier(): ShippingCarrier
    {
        return ShippingCarrier::first() ?? ShippingCarrier::create([
            'code' => 'test-carrier',
            'name' => ['ko' => '테스트택배', 'en' => 'Test Carrier'],
            'is_active' => true,
        ]);
    }

    // ================================================================
    // 취소 상세 — 조회 전용 (주문상세 클레임 내역·클레임 목록 취소 탭의 번호가 여는 화면)
    // ================================================================

    /**
     * 취소 신청서 한 건을 만듭니다. (취소 옵션 행 + 취소 발 환불 행)
     *
     * 실서비스는 `OrderCancellationService` 가 세 행을 함께 만든다 — 상세는 취소 행에서
     * 구분·사유를, 취소 발 환불 행에서 환불 상태·금액을 읽는다.
     *
     * @param  Order|null  $order  대상 주문 (없으면 결제완료 주문을 새로 만든다)
     * @return OrderCancel 취소 신청서
     */
    protected function makeCancel(?Order $order = null): OrderCancel
    {
        $order ??= $this->paidClaimOrder();
        $option = $order->options->first();

        $cancel = OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => 'CN-DETAIL-'.uniqid(),
            'cancel_type' => CancelTypeEnum::PARTIAL,
            'cancel_status' => CancelStatusEnum::COMPLETED,
            'cancel_reason_type' => 'changed_mind',
            'cancel_amount' => (float) $option->unit_price,
            'items_snapshot' => [],
        ]);

        OrderCancelOption::create([
            'order_cancel_id' => $cancel->id,
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'cancel_quantity' => 1,
            'original_quantity' => (int) $option->quantity,
            'unit_price' => (float) $option->unit_price,
            'subtotal_amount' => (float) $option->unit_price,
            'option_status' => CancelOptionStatusEnum::COMPLETED,
        ]);

        OrderRefund::create([
            'order_id' => $order->id,
            'order_cancel_id' => $cancel->id,
            'refund_source_type' => RefundSourceTypeEnum::CANCEL,
            'refund_number' => 'RF-CANCEL-'.uniqid(),
            'refund_status' => RefundStatusEnum::COMPLETED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => (float) $option->unit_price,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
        ]);

        return $cancel->fresh(['refund', 'cancelOptions']);
    }

    /**
     * 취소 번호도 같은 상세 봉투로 열린다. 취소에는 전이·송장·청구·환불 실행 축이 없어 그 자리가
     * 비고(세그먼트 null · 택배사/청구 방식/환불 수단 빈 배열 · 정산/취소 가능 금액 null), 화면은
     * 취소를 조회 전용으로 그린다. 종전에는 이 번호가 404 라 클레임 목록 취소 탭의 번호를 누르면
     * 오류 화면이 열렸다.
     *
     * @scenario claim_type=cancel, claim_stage=final
     *
     * @effects claim_detail_cancel_resolves_by_number, claim_detail_cancel_is_read_only
     *
     * @return void 반환값 없음
     */
    public function test_detail_by_number_resolves_a_cancel_as_a_read_only_detail(): void
    {
        $cancel = $this->makeCancel();

        $response = $this->actingAs($this->admin)
            ->getJson($this->base().'/claims/'.$cancel->cancel_number)
            ->assertOk()
            ->assertJsonPath('data.claim.claim_number', $cancel->cancel_number)
            ->assertJsonPath('data.claim.claim_type', 'cancel')
            ->assertJsonPath('data.claim.cancel_type', 'partial')
            ->assertJsonPath('data.claim.refund_status', 'completed')
            ->assertJsonPath('data.claim_path_segment', null)
            ->assertJsonPath('data.carriers', [])
            ->assertJsonPath('data.charge_methods', [])
            ->assertJsonPath('data.refund_methods', [])
            ->assertJsonPath('data.settlement', null)
            ->assertJsonPath('data.cancellable', null)
            ->assertJsonPath('data.redelivery_address_differs', null);

        // 환불 금액은 취소 발 환불 행에서 온다 — 서버가 표기까지 만든다.
        $this->assertNotNull($response->json('data.claim.refund_amount_formatted'));
        $this->assertCount(1, $response->json('data.claim.items'), '취소 상품 행이 실리지 않았다');

        // 진행단계는 취소 상태 Enum 의 두 칸이다 (신청 → 완료)
        $steps = $response->json('data.progress_steps');
        $this->assertSame(['requested', 'completed'], array_column($steps, 'value'));
        $this->assertContains($steps[1]['state'], ['current', 'done']);

        // 결제 정보 섹션이 그리는 주문 봉투도 함께 실린다
        $this->assertSame($cancel->order_id, $response->json('data.order.id'));
    }

    /**
     * 취소는 추가결제·첨부 축이 없고, 대신 취소 발 환불 행의 이력이 같은 신청서의 것이다.
     *
     * @scenario claim_type=cancel, claim_stage=final
     *
     * @effects claim_logs_include_cancel_linked_refund
     *
     * @return void 반환값 없음
     */
    public function test_logs_for_a_cancel_merge_its_linked_refund_entries(): void
    {
        $cancel = $this->makeCancel();
        $other = $this->makeCancel($this->paidClaimOrder());

        $this->logFor($cancel->getMorphClass(), $cancel->id, 'order.cancel');
        $this->logFor($cancel->refund->getMorphClass(), $cancel->refund->id, 'claim_refund.execute');
        $this->logFor($other->refund->getMorphClass(), $other->refund->id, 'claim_refund.complete');

        $actions = array_column(
            $this->actingAs($this->admin)
                ->getJson($this->base().'/claims/'.$cancel->cancel_number.'/logs')
                ->assertOk()
                ->json('data.data'),
            'action',
        );

        $this->assertContains('order.cancel', $actions, '취소 행 자신의 로그가 빠졌다');
        $this->assertContains('claim_refund.execute', $actions, '취소 발 환불 행의 로그가 빠졌다');
        $this->assertNotContains('claim_refund.complete', $actions, '다른 취소의 환불 로그가 섞였다');
    }
}
