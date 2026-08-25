<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 비회원 클레임 API Feature 테스트 (S5 · D8)
 *
 * 검증 축 (토큰 인증):
 * - 모든 클레임 경로가 `X-Guest-Order-Token` 없이는 404 (존재 여부 비노출)
 * - 신청 가능 옵션 / 미리보기 / 반품·환불 신청 / 이 주문의 클레임 목록 / 상세 / 수정 / 철회
 * - 첨부 업로드·삭제는 토큰 주문 소속만
 * - 마일리지 결제는 비회원에게 열리지 않는다 (422)
 * - 다른 주문의 토큰으로는 이 주문의 신청서에 닿지 않는다
 */
class GuestClaimApiTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    private const PASSWORD = 'guest12';

    private const PHONE = '010-1234-5678';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();

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

    /**
     * 배송완료 비회원 주문과 유효 토큰을 만듭니다.
     *
     * @param  OrderStatusEnum  $status  주문 상태
     * @return array{0: Order, 1: string} [주문, 토큰]
     */
    protected function guestOrderWithToken(OrderStatusEnum $status = OrderStatusEnum::DELIVERED, int $quantity = 1): array
    {
        $order = $status === OrderStatusEnum::DELIVERED
            ? $this->deliveredClaimOrder(quantity: $quantity)
            : $this->paidClaimOrder(quantity: $quantity);
        $order->forceFill(['user_id' => null, 'guest_lookup_password_hash' => Hash::make(self::PASSWORD)])->save();
        OrderAddress::where('order_id', $order->id)->update(['orderer_phone' => self::PHONE]);

        if ($status !== OrderStatusEnum::DELIVERED) {
            $order = $this->moveOrderTo($order->fresh(['options']), $status);
        }

        $token = app(GuestOrderAuthService::class)->authenticate($order->order_number, self::PHONE, self::PASSWORD, '10.0.0.9')['token'];

        return [$order->fresh(['options', 'shippingAddress']), $token];
    }

    protected function base(Order $order): string
    {
        return "/api/modules/sirsoft-ecommerce/guest/orders/{$order->order_number}";
    }

    protected function items(Order $order, int $quantity = 1): array
    {
        return [['order_option_id' => $order->options->first()->id, 'quantity' => $quantity]];
    }

    public function test_every_claim_route_is_token_protected(): void
    {
        [$order] = $this->guestOrderWithToken();

        $this->getJson($this->base($order).'/claimable-options')->assertNotFound();
        $this->postJson($this->base($order).'/claims/preview', ['type' => 'return', 'items' => $this->items($order)])->assertNotFound();
        $this->postJson($this->base($order).'/returns', ['items' => $this->items($order), 'reason_code' => 'changed_mind'])->assertNotFound();
        $this->getJson($this->base($order).'/claims')->assertNotFound();
        $this->postJson($this->base($order).'/claims/images', [])->assertNotFound();
        $this->postJson($this->base($order).'/claim-payments/CP1/pay', [])->assertNotFound();
        $this->getJson($this->base($order).'/claims', ['X-Guest-Order-Token' => 'invalid-token'])->assertNotFound();
    }

    public function test_claimable_options_and_preview_work_with_the_token(): void
    {
        [$order, $token] = $this->guestOrderWithToken();

        $this->getJson($this->base($order).'/claimable-options', ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.options.0.claimable_types', ['exchange', 'return']);

        $this->postJson($this->base($order).'/claims/preview', [
            'type' => 'return', 'items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund',
        ], ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.total_charge_amount', 3000)
            ->assertJsonPath('data.requires_payment', false);
    }

    public function test_guest_return_request_is_filed_without_a_requester(): void
    {
        [$order, $token] = $this->guestOrderWithToken();

        $response = $this->postJson($this->base($order).'/returns', [
            'items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund',
        ], ['X-Guest-Order-Token' => $token]);

        $response->assertCreated()
            ->assertJsonPath('data.claim.claim_type', 'return')
            ->assertJsonPath('data.claim.is_guest_request', true)
            ->assertJsonPath('data.claim.requested_by', null);

        $number = $response->json('data.claim.claim_number');

        // 이 주문의 클레임 목록 / 상세 / 수정 / 철회
        $this->getJson($this->base($order).'/claims', ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.counts.return', 1)
            ->assertJsonPath('data.claims.return.0.claim_number', $number);

        $this->getJson($this->base($order)."/claims/{$number}", ['X-Guest-Order-Token' => $token])
            ->assertOk()->assertJsonPath('data.claim.claim_number', $number);

        $this->patchJson($this->base($order)."/claims/{$number}", ['reason_detail' => '비회원 수정'], ['X-Guest-Order-Token' => $token])
            ->assertOk()->assertJsonPath('data.claim.reason_detail', '비회원 수정');

        $this->postJson($this->base($order)."/claims/{$number}/withdraw", [], ['X-Guest-Order-Token' => $token])
            ->assertOk()->assertJsonPath('data.claim.status', 'withdrawn');
    }

    public function test_guest_exchange_request_enters_payment_pending(): void
    {
        // 비회원 신청 3유형 중 교환만 이 스위트에 없었다 — 라우트를 지워도 전 스위트가 green 인
        // 상태였다. 교환은 왕복 배송비를 청구하므로 접수 즉시 결제 진입 4키가 함께 나가야 한다.
        $this->registerTestPgProvider();
        [$order, $token] = $this->guestOrderWithToken();

        $this->postJson($this->base($order).'/exchanges', [
            'items' => $this->items($order),
            'reason_code' => 'size_mismatch',
            'charge_method' => 'pay_on_request',
            'payment_method' => 'card',
        ], ['X-Guest-Order-Token' => $token])
            ->assertCreated()
            ->assertJsonPath('data.claim.claim_type', 'exchange')
            ->assertJsonPath('data.claim.is_guest_request', true)
            ->assertJsonPath('data.claim.requested_by', null)
            ->assertJsonPath('data.claim.status', 'payment_pending')
            ->assertJsonPath('data.requires_pg_payment', true)
            ->assertJsonPath('data.pg_payment_handler', 'sirsoft-testpg.requestPayment')
            ->assertJsonPath('data.pg_payment_data.amount', 6000)
            ->assertJsonPath('data.payment.payment_status', 'pending');
    }

    public function test_guest_pg_approval_confirms_the_claim_payment(): void
    {
        // 비회원 추가결제의 '확정' 경로. 시작(pay)은 실패 케이스만 있었고 승인 콜백은 호출조차
        // 되지 않아, 이 라우트가 죽어도 red 가 나지 않았다.
        $this->registerTestPgProvider();
        $this->fakeClaimPaymentApprove(true);
        [$order, $token] = $this->guestOrderWithToken();

        $result = app(UserClaimService::class)->requestReturn($order, [
            'items' => $this->items($order), 'reason_code' => 'changed_mind',
            'charge_method' => 'pay_on_request', 'requested_by' => null,
        ], [], 'card');
        $number = $result['payment']->payment_number;

        $this->postJson($this->base($order)."/claim-payments/{$number}/approve", [
            'callback' => ['paymentKey' => 'pk'],
        ], ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.payment.payment_status', 'paid');

        // 수납이 끝난 신청서는 자동 승인 여부와 무관하게 `paid` 를 거친다.
        $this->assertSame('paid', $result['claim']->fresh()->return_status->value);

        // 다른 주문의 토큰으로는 이 결제 건에 닿지 않는다.
        [$other, $otherToken] = $this->guestOrderWithToken();
        $this->postJson($this->base($other)."/claim-payments/{$number}/approve", [
            'callback' => ['paymentKey' => 'pk'],
        ], ['X-Guest-Order-Token' => $otherToken])->assertNotFound();
    }

    public function test_guest_refund_request_branches_to_instant_cancel(): void
    {
        [$order, $token] = $this->guestOrderWithToken(OrderStatusEnum::PAYMENT_COMPLETE);

        $this->postJson($this->base($order).'/refund-requests', [
            'items' => $this->items($order), 'reason_code' => 'changed_mind',
        ], ['X-Guest-Order-Token' => $token])
            ->assertCreated()
            ->assertJsonPath('data.branch', 'cancel');

        $this->assertSame(OrderStatusEnum::CANCELLED, $order->fresh()->order_status);
    }

    public function test_another_orders_token_cannot_reach_this_orders_claim(): void
    {
        [$order, $token] = $this->guestOrderWithToken();
        [$other, $otherToken] = $this->guestOrderWithToken();
        $number = $this->postJson($this->base($order).'/returns', [
            'items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund',
        ], ['X-Guest-Order-Token' => $token])->json('data.claim.claim_number');

        $this->getJson($this->base($other)."/claims/{$number}", ['X-Guest-Order-Token' => $otherToken])->assertNotFound();
        $this->postJson($this->base($other)."/claims/{$number}/withdraw", [], ['X-Guest-Order-Token' => $otherToken])->assertNotFound();
        // 토큰과 주문번호가 어긋나면 미들웨어가 404 로 막는다.
        $this->getJson($this->base($order)."/claims/{$number}", ['X-Guest-Order-Token' => $otherToken])->assertNotFound();
    }

    public function test_guest_image_upload_attach_and_delete(): void
    {
        [$order, $token] = $this->guestOrderWithToken();
        [$other, $otherToken] = $this->guestOrderWithToken();

        $upload = $this->postJson($this->base($order).'/claims/images', [
            'image' => UploadedFile::fake()->image('claim.jpg'),
        ], ['X-Guest-Order-Token' => $token]);
        $upload->assertCreated()->assertJsonPath('data.claimable_type', ClaimImage::PENDING_CLAIMABLE_TYPE);
        $imageId = $upload->json('data.id');

        // 다른 주문의 토큰으로는 삭제할 수 없고, 신청에 귀속시킬 수도 없다.
        $this->deleteJson($this->base($other)."/claims/images/{$imageId}", [], ['X-Guest-Order-Token' => $otherToken])->assertNotFound();
        $this->postJson($this->base($other).'/returns', [
            'items' => $this->items($other), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'image_ids' => [$imageId],
        ], ['X-Guest-Order-Token' => $otherToken])->assertStatus(422);

        // 거절된 신청은 신청서를 남기지 않아야 한다 — 접수가 자체 트랜잭션으로 커밋된 뒤 첨부 귀속에서
        // 거절하면, 사용자는 422 를 보는데 신청서는 살아남아 그 상품의 수량 잠금까지 잡는다(재신청 불가).
        $this->assertSame(0, OrderReturn::where('order_id', $other->id)->count());

        $this->postJson($this->base($order).'/returns', [
            'items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'image_ids' => [$imageId],
        ], ['X-Guest-Order-Token' => $token])->assertCreated()->assertJsonPath('data.claim.images.0.id', $imageId);

        $this->assertSame('return', ClaimImage::find($imageId)->claimable_type);
    }

    public function test_guest_order_claim_list_carries_product_names_and_reads_reasons_in_one_query(): void
    {
        // 이 목록은 주문상세의 "이 주문의 클레임" 섹션을 그린다. 두 축이 조용히 깨질 수 있다:
        //  ① 목록이 `.orderOption` 을 안 실으면 `whenLoaded` 가 정상 동작한 결과로 상품명·썸네일이
        //     오류 없이 전부 null 이 된다 (회원 목록·상세는 싣는다).
        //  ② 사유 라벨을 행마다 되읽으면 목록 1회가 건수만큼의 추가 쿼리가 된다.
        [$order, $token] = $this->guestOrderWithToken(quantity: 2);
        $option = $order->options->first();

        // 시드 사유의 표시명은 코드와 같아 라벨 해석 실패를 가릴 수 있다 — 구분되는 이름을 준다.
        ClaimReason::where('type', 'return')->where('code', 'changed_mind')
            ->update(['name' => ['ko' => '단순 변심', 'en' => 'Changed mind']]);
        ClaimReason::where('type', 'return')->where('code', 'size_mismatch')
            ->update(['name' => ['ko' => '사이즈 불일치', 'en' => 'Size mismatch']]);

        foreach (['changed_mind', 'size_mismatch'] as $reason) {
            $this->postJson($this->base($order).'/returns', [
                'items' => [['order_option_id' => $option->id, 'quantity' => 1]],
                'reason_code' => $reason,
                'charge_method' => 'deduct_from_refund',
            ], ['X-Guest-Order-Token' => $token])->assertCreated();
        }

        $reasonQueries = 0;
        DB::listen(function ($query) use (&$reasonQueries) {
            if (str_contains($query->sql, 'ecommerce_claim_reasons')) {
                $reasonQueries++;
            }
        });

        $rows = $this->getJson($this->base($order).'/claims', ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->json('data.claims.return');

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertNotNull(
                $row['items'][0]['product_name'],
                '비회원 목록의 상품명이 null 이면 화면이 빈 칸으로 뜬다 — 목록 조회가 `.orderOption` 을 싣는지 확인할 것.',
            );
        }

        // 테스트 로케일(en)의 표시명으로 해석되어야 한다 — 코드 그대로면 카탈로그 해석이 실패한 것이다.
        $this->assertSame(
            ['Changed mind', 'Size mismatch'],
            collect($rows)->pluck('reason_label')->sort()->values()->all(),
            '사유 라벨이 카탈로그 표시명으로 해석되지 않았습니다.',
        );

        // 사유 카탈로그 조회는 **행 수와 무관**해야 한다 — 이 응답에 실린 유형은 반품 하나뿐이므로
        // 묶음 조회면 1회다. 행마다 되읽으면 행 수(2)만큼 나온다.
        $this->assertSame(
            1,
            $reasonQueries,
            "사유 카탈로그 조회가 {$reasonQueries}회입니다 — 행 수에 비례하면 목록이 커질수록 쿼리가 늘어납니다.",
        );
    }

    public function test_guest_cannot_pay_with_mileage(): void
    {
        [$order, $token] = $this->guestOrderWithToken();
        $result = app(UserClaimService::class)->requestReturn($order, [
            'items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'pay_on_request', 'requested_by' => null,
        ], [], 'card');

        $this->postJson($this->base($order)."/claim-payments/{$result['payment']->payment_number}/pay", ['payment_method' => 'point'], ['X-Guest-Order-Token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_point_payment_requires_member'));

        // 거절된 요청은 결제행을 건드리지 않아야 한다 — 가드가 쓰기 뒤에 있으면 422 를 받고도
        // 결제 수단이 마일리지(`amount=0`·`pg_provider=null`)로 바뀐 채 커밋된다.
        $fresh = $result['payment']->fresh();
        $this->assertSame('card', $fresh->payment_method);
        $this->assertSame(0.0, (float) $fresh->points_amount);
        $this->assertEqualsWithDelta((float) $result['payment']->amount, (float) $fresh->amount, 0.001);
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $fresh->payment_status);
    }

    public function test_a_guest_requesting_with_mileage_leaves_no_claim_and_no_payment_row(): void
    {
        // 마일리지-회원 가드가 결제행을 **만든 뒤**에만 서면, 신청은 422 인데 신청서(수량 잠금)와
        // `point` 가 박힌 결제행이 커밋된다. 그 결제행은 클라이언트가 다른 수단을 명시하지 않는 한
        // 다시 열리지 않는다. FormRequest 는 허용 목록만 보므로 서비스가 유일한 판정 지점이다.
        [$order, $token] = $this->guestOrderWithToken();

        $this->postJson($this->base($order).'/returns', [
            'items' => $this->items($order),
            'reason_code' => 'changed_mind',
            'charge_method' => 'pay_on_request',
            'payment_method' => 'point',
        ], ['X-Guest-Order-Token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_point_payment_requires_member'));

        $this->assertSame(0, OrderReturn::where('order_id', $order->id)->count(), '거절된 신청이 신청서를 남기면 그 수량이 잠겨 재신청이 막힙니다.');
        $this->assertSame(0, ClaimPayment::query()->count(), '거절된 신청이 결제행을 남기면 그 건은 다시 열리지 않습니다.');
    }

    public function test_the_order_claim_section_carries_the_carrier_name_and_order_number(): void
    {
        // 회원 목록은 `order`·배송사 관계를 싣는다. 비회원 섹션만 빠지면 오류 없이 그 필드가 전 행
        // null 이 되어 배송조회 열이 비고, 화면은 정상 응답을 받은 것으로 보인다.
        [$order, $token] = $this->guestOrderWithToken();
        $carrier = ShippingCarrier::query()->create([
            'code' => 'claim-track-test', 'name' => ['ko' => 'CJ대한통운'], 'type' => 'domestic',
            'tracking_url' => 'https://track.example.test/{tracking_number}',
            'is_active' => true, 'sort_order' => 1,
        ]);

        $result = app(UserClaimService::class)->requestReturn($order, [
            'items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'requested_by' => null,
        ]);
        $result['claim']->forceFill([
            'collection_carrier_id' => $carrier->id,
            'collection_tracking_number' => '651234567890',
        ])->save();

        $response = $this->getJson($this->base($order).'/claims', ['X-Guest-Order-Token' => $token])->assertOk();

        $row = $response->json('data.claims.return.0');
        $this->assertSame($order->order_number, $row['order_number']);
        $this->assertNotNull($row['collection_carrier_name'], '배송사 관계가 없으면 이름이 무음 null 이 됩니다.');
        $this->assertSame('https://track.example.test/651234567890', $row['collection_tracking_url']);
    }

    public function test_the_refund_bank_account_is_masked_on_every_guest_facing_path(): void
    {
        // 마스킹은 한 경로만 가려도 다른 경로가 그대로 우회로가 된다 — 같은 토큰으로 열리는
        // 주문상세와 클레임 응답이 **같은 강도**여야 한다.
        [$order, $token] = $this->guestOrderWithToken();

        $order->payment()->update([
            'refund_bank_code' => '004',
            'refund_bank_account' => '110123456789',
            'refund_bank_holder' => '홍길동',
        ]);

        $payment = $this->getJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->order_number}",
            ['X-Guest-Order-Token' => $token],
        )->assertOk()->json('data.payment');

        $this->assertSame('********6789', $payment['refund_bank_account']);
        $this->assertSame('홍**', $payment['refund_bank_holder']);
        $this->assertStringNotContainsString('110123456789', json_encode($payment, JSON_UNESCAPED_UNICODE));
    }

    public function test_an_expired_token_no_longer_reaches_the_claim_routes(): void
    {
        // 토큰은 HMAC 안에 만료시각을 담고 있어 서버 상태 없이 만료된다. TTL 이 지난 뒤에도
        // 열려 있으면 발급 한 번으로 그 주문의 클레임 경로가 영구히 열린 채가 된다.
        // 만료는 '없는 토큰' 과 같은 처리를 받는다 — 404 로 주문 존재 여부를 노출하지 않는다.
        [$order, $token] = $this->guestOrderWithToken();

        $this->getJson($this->base($order).'/claims', ['X-Guest-Order-Token' => $token])->assertOk();

        $this->travel(GuestOrderAuthService::TOKEN_TTL_MINUTES + 1)->minutes();

        $this->getJson($this->base($order).'/claims', ['X-Guest-Order-Token' => $token])->assertNotFound();
        $this->getJson($this->base($order).'/claimable-options', ['X-Guest-Order-Token' => $token])->assertNotFound();

        $this->travelBack();
    }

    public function test_missing_payment_is_404_with_the_payment_specific_message(): void
    {
        // 회원 축과 같은 문구여야 한다 — 두 축이 같은 트레이트를 쓰는데 한쪽만 고쳐지는 일이 실제로 있었다.
        // 기대 문구는 요청 뒤에 해석한다 — 요청 처리 중 로케일이 정해진다.
        [$order, $token] = $this->guestOrderWithToken();

        $this->postJson($this->base($order).'/claim-payments/CP-NOPE/pay', ['payment_method' => 'card'], ['X-Guest-Order-Token' => $token])
            ->assertNotFound()
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_payment_not_found'));

        $this->postJson($this->base($order).'/claim-payments/CP-NOPE/approve', ['callback' => ['x' => 1]], ['X-Guest-Order-Token' => $token])
            ->assertNotFound()
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_payment_not_found'));

        $this->assertNotSame(
            __('sirsoft-ecommerce::exceptions.claim_payment_not_found'),
            __('sirsoft-ecommerce::exceptions.claim_not_found'),
        );
    }

    // ================================================================
    // 취소 미리보기 비대칭 해소 (S3 2차 PO 확정 ② — 비회원 면)
    // ================================================================

    /**
     * 클레임 코스에 잠긴 상품은 비회원 취소 미리보기도 422 다.
     *
     * 회원 축과 같은 이유다 — `previewRefund()` 기본 가드가 세 면에 새 422 경로를 만들었는데
     * 비회원 컨트롤러는 관리자와 다른 메시지 키(`order_cancel_failed`)를 쓴다. 이 면을
     * 단언하지 않으면 매핑이 끊겨도 회귀가 초록으로 남는다.
     */
    public function test_estimate_refund_is_422_for_items_locked_by_a_claim_course(): void
    {
        [$order, $token] = $this->guestOrderWithToken();

        $this->postJson($this->base($order).'/returns', [
            'items' => $this->items($order),
            'reason_code' => 'changed_mind',
            'charge_method' => 'deduct_from_refund',
        ], ['X-Guest-Order-Token' => $token])->assertStatus(201);

        $response = $this->postJson($this->base($order).'/estimate-refund', [
            'items' => [['order_option_id' => $order->options->first()->id, 'cancel_quantity' => 1]],
        ], ['X-Guest-Order-Token' => $token]);

        $response->assertStatus(422);

        // 비회원도 회원·관리자와 같은 사유를 받는다 — 세 면이 같은 키를 쓴다.
        $this->assertSame(
            __('sirsoft-ecommerce::exceptions.claim_option_in_course', [
                'status' => OrderStatusEnum::RETURN_IN_PROGRESS->label(),
            ]),
            $response->json('message'),
        );
    }
}
