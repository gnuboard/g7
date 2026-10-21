<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Console;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * playwright:seed-claim-detail — 관리자 클레임 상세 E2E 가 끝까지 잴 신청서 셋을 세우고, 워커별로 지운다 (#757).
 *
 * 상세 spec 의 세 시험(옵션 변경 교환의 소계·차액 · 상품 1건 승인 반품의 송장 입력 · 계좌 빈 승인 환불의 확인 창)은
 * 운영 데이터에 그 모양이 없으면 건너뛰어 통과로 세어졌다. 이 커맨드가 그 전제를 세우는데, 브라우저가 보는 값은
 * **관리자 상세 응답**이므로 여기서도 그 응답으로 잰다 — 행이 생겼는지가 아니라 화면이 읽을 판정이 서는지.
 *
 * `fullyParallel` 워커마다 다른 tag 로 시드하므로, 한 tag 의 재시드·정리가 다른 tag 를 지우지 않아야 한다.
 *
 * 시나리오 매니페스트: `modules/_bundled/sirsoft-ecommerce/tests/scenarios/playwright-claim-detail-seed.yaml`
 */
class PlaywrightSeedClaimDetailCommandTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /** 상세를 여는 운영자 (전이·금액·환불 실행 권한) */
    protected User $admin;

    /**
     * 통화·사유를 준비하고 옵트인 플래그를 켭니다.
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
            'sirsoft-ecommerce.claims.update-amounts',
            'sirsoft-ecommerce.claims.execute-refund',
        ]);

        $_SERVER['G7_PLAYWRIGHT_BYPASS'] = '1';
        $_ENV['G7_PLAYWRIGHT_BYPASS'] = '1';
        putenv('G7_PLAYWRIGHT_BYPASS=1');
    }

    /**
     * 옵트인 플래그와 통화 설정을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        unset($_SERVER['G7_PLAYWRIGHT_BYPASS'], $_ENV['G7_PLAYWRIGHT_BYPASS']);
        putenv('G7_PLAYWRIGHT_BYPASS');
        $this->clearTestCurrencySettings();

        parent::tearDown();
    }

    /**
     * 커맨드를 JSON 모드로 실행하고 결과를 돌려줍니다.
     *
     * @param  array<string, mixed>  $options  커맨드 옵션
     * @return array{0: int, 1: array<string, mixed>} [종료 코드, JSON 결과]
     */
    private function runSeed(array $options): array
    {
        $code = Artisan::call('playwright:seed-claim-detail', $options + ['--json' => true]);
        $lines = array_values(array_filter(
            preg_split('/\R/', trim(Artisan::output())) ?: [],
            fn (string $l) => str_starts_with(trim($l), '{')
        ));

        return [$code, $lines === [] ? [] : (json_decode(end($lines), true) ?? [])];
    }

    /**
     * 관리자 상세 응답의 `data` 를 돌려줍니다.
     *
     * @param  string  $claimNumber  클레임 번호
     * @return array<string, mixed> 상세 봉투
     */
    private function detail(string $claimNumber): array
    {
        return $this->actingAs($this->admin)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/claims/'.rawurlencode($claimNumber))
            ->assertOk()
            ->json('data');
    }

    /**
     * 시드 한 벌의 행 수를 셉니다. (주문·신청서·상품·배송정책)
     *
     * @param  string  $tag  워커별 꼬리표
     * @return array<string, int> 행 수
     */
    private function countsFor(string $tag): array
    {
        $orderIds = Order::withTrashed()->where('order_number', 'like', 'E2E-CLM-'.$tag.'-%')->pluck('id');
        $product = Product::withTrashed()->where('product_code', 'E2ECLM'.$tag)->first();

        return [
            'orders' => $orderIds->count(),
            'exchanges' => OrderExchange::query()->whereIn('order_id', $orderIds)->count(),
            'returns' => OrderReturn::query()->whereIn('order_id', $orderIds)->count(),
            'refunds' => OrderRefund::query()->whereIn('order_id', $orderIds)->count(),
            'products' => $product ? 1 : 0,
            'policies' => $product && $product->shipping_policy_id
                ? ShippingPolicy::query()->whereKey($product->shipping_policy_id)->count()
                : 0,
        ];
    }

    /**
     * 세 신청서가 상세 화면이 읽을 판정과 함께 선다 — 옵션 변경 교환(수량 2·차액), 회수중으로 옮길 1건 반품(송장 없음),
     * 계좌 없이 계좌 환불이면 계좌를 요구하는 승인 환불.
     *
     * @scenario op=seed, tag=valid
     *
     * @effects seeds_option_change_exchange_with_quantity_two, seeds_collectable_single_item_return, seeds_approved_refund_without_account
     *
     * @return void 반환값 없음
     */
    public function test_seed_builds_the_three_claims_the_detail_screen_reads(): void
    {
        [$code, $seed] = $this->runSeed(['--tag' => 'w0t1']);

        $this->assertSame(0, $code, Artisan::output());
        $this->assertSame('E2ECLM', $seed['claim_mark']);
        foreach (['exchange_number', 'return_number', 'refund_number'] as $key) {
            $this->assertStringContainsString('E2ECLM', $seed[$key], "{$key} 에 시드 표식이 없다 — spec 의 운영 데이터 발견이 시드를 거르지 못한다");
        }

        // 교환 — 옵션 변경 행, 수량 2, 차액 ≠ 0, 교환 후 금액은 소계
        $exchange = $this->detail($seed['exchange_number']);
        $this->assertSame('exchange', $exchange['claim']['claim_type']);
        $items = $exchange['claim']['items'];
        $this->assertCount(1, $items);
        $this->assertTrue($items[0]['is_option_change']);
        $this->assertSame(2, $items[0]['quantity']);
        $this->assertNotEquals(0, (float) $items[0]['price_difference']);
        $this->assertEqualsWithDelta((float) $items[0]['new_unit_price'] * 2, (float) $items[0]['new_subtotal_amount'], 0.001);
        $this->assertNotSame($items[0]['subtotal_amount_formatted'], $items[0]['new_subtotal_amount_formatted']);

        // 반품 — 상품 1건, 행 선택지에 회수중, 저장된 회수 송장 없음
        $return = $this->detail($seed['return_number']);
        $this->assertSame('return', $return['claim']['claim_type']);
        $this->assertCount(1, $return['claim']['items']);
        $this->assertContains('collecting', array_column($return['claim']['items'][0]['transition_options'], 'value'));
        $this->assertNull($return['claim']['items'][0]['collection_tracking_number']);

        // 환불 — 승인, 계좌 없음 → 계좌 환불이면 현금이 있을 때 계좌 필요, 저장 현금 > 0, 실행 가능
        $refund = $this->detail($seed['refund_number']);
        $this->assertSame('refund', $refund['claim']['claim_type']);
        $this->assertSame('approved', $refund['claim']['status']);
        $this->assertTrue($refund['claim']['refund_account_by_method']['bank']['required_if_cash']);
        $this->assertGreaterThan(0, (float) $refund['claim']['refund_amount']);
        $this->assertContains('bank', array_column($refund['refund_methods'], 'value'));
        $this->assertTrue($refund['abilities']['can_execute_refund']);
        $this->assertNotNull($refund['cancellable']);
    }

    /**
     * 같은 tag 로 다시 시드하면 앞 벌을 지우고 한 벌만 남는다.
     *
     * @scenario op=reseed, tag=valid
     *
     * @effects reseed_same_tag_replaces_previous_set
     *
     * @return void 반환값 없음
     */
    public function test_reseed_with_the_same_tag_keeps_a_single_set(): void
    {
        $this->runSeed(['--tag' => 'w0t1']);
        [$code] = $this->runSeed(['--tag' => 'w0t1']);

        $this->assertSame(0, $code);
        $this->assertSame(
            ['orders' => 3, 'exchanges' => 1, 'returns' => 1, 'refunds' => 1, 'products' => 1, 'policies' => 1],
            $this->countsFor('w0t1'),
        );
    }

    /**
     * `--teardown --tag` 는 그 tag 만 지운다 — 다른 워커의 신청서가 시험 도중 사라지지 않는다.
     *
     * @scenario op=teardown, tag=valid
     *
     * @effects teardown_by_tag_keeps_other_tags
     *
     * @return void 반환값 없음
     */
    public function test_teardown_with_a_tag_removes_only_that_tag(): void
    {
        $this->runSeed(['--tag' => 'w0t1']);
        $this->runSeed(['--tag' => 'w1t1']);

        [$code, $result] = $this->runSeed(['--tag' => 'w0t1', '--teardown' => true]);

        $this->assertSame(0, $code);
        $this->assertSame(3, $result['deleted_orders']);
        $this->assertSame(
            ['orders' => 0, 'exchanges' => 0, 'returns' => 0, 'refunds' => 0, 'products' => 0, 'policies' => 0],
            $this->countsFor('w0t1'),
        );
        $this->assertSame(
            ['orders' => 3, 'exchanges' => 1, 'returns' => 1, 'refunds' => 1, 'products' => 1, 'policies' => 1],
            $this->countsFor('w1t1'),
        );
    }

    /**
     * `--teardown` 단독은 이 커맨드가 남긴 전부를 지운다 — 운영 주문은 건드리지 않는다.
     *
     * @scenario op=teardown, tag=omitted
     *
     * @effects teardown_without_tag_sweeps_every_seed_only
     *
     * @return void 반환값 없음
     */
    public function test_teardown_without_a_tag_sweeps_every_seed_and_nothing_else(): void
    {
        $operational = Order::factory()->forGuest()->create(['order_number' => 'ORD-KEEP-0001']);
        $this->runSeed(['--tag' => 'w0t1']);
        $this->runSeed(['--tag' => 'w1t1']);

        [$code, $result] = $this->runSeed(['--teardown' => true]);

        $this->assertSame(0, $code);
        $this->assertSame(6, $result['deleted_orders']);
        $this->assertSame(2, $result['deleted_products']);
        $this->assertSame(0, Order::withTrashed()->where('order_number', 'like', 'E2E-CLM-%')->count());
        $this->assertSame(0, Product::withTrashed()->where('product_code', 'like', 'E2ECLM%')->count());
        $this->assertNotNull(Order::query()->find($operational->id), '운영 주문까지 지웠다');
    }

    /**
     * tag 없이 시드하거나 tag 가 형식에 맞지 않으면 아무것도 만들지 않고 실패한다.
     *
     * @scenario op=seed, tag=invalid
     *
     * @effects seed_without_valid_tag_fails_without_side_effects
     *
     * @return void 반환값 없음
     */
    public function test_seed_without_a_valid_tag_fails_without_side_effects(): void
    {
        $this->assertSame(1, Artisan::call('playwright:seed-claim-detail', ['--json' => true]));
        $this->assertSame(1, Artisan::call('playwright:seed-claim-detail', ['--tag' => 'bad-tag!', '--json' => true]));

        $this->assertSame(0, Order::withTrashed()->where('order_number', 'like', 'E2E-CLM-%')->count());
        $this->assertSame(0, Product::withTrashed()->where('product_code', 'like', 'E2ECLM%')->count());
    }
}
