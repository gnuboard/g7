<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Exports\Types\OrderExportType;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 주문 목록 결제수단 필터 — PG 별칭 결제수단 (#933 S6·D8)
 *
 * 토스 결제수단(`toss_*`)은 주문 생성 때 코어 값(`card`·`vbank`)으로 저장된다. 그래서 필터에서 `toss_kakaopay` 를 고르면
 * `payment_method IN ('toss_kakaopay')` 가 되어 0건이었고, 「신용카드」(`card`)에는 토스 간편결제 주문이 섞였다.
 * 별칭은 `payment_method = core AND pg_provider = P AND embedded_pg_provider = E` 로 찾고, 코어 값 필터는 **활성** 간편결제 별칭이
 * 찾는 주문을 뺀다(나이스·KCP 간편결제가 자기 id 로 저장돼 「신용카드」에 섞이지 않는 것과 맞춘다). 비활성 별칭의 주문은 선택지가
 * 없으므로 코어 값 필터에 남아 반드시 어느 선택지로든 찾을 수 있다.
 *
 * 주문 시드
 *
 * | 주문 | payment_method | pg_provider | embedded |
 * |---|---|---|---|
 * | O1 | card | tosspayments | null |
 * | O2 | card | tosspayments | 카카오페이 |
 * | O3 | card | tosspayments | 엘페이 (별칭 비활성) |
 * | O4 | card | nicepayments | null |
 * | O5 | nicepay_kakaopay | nicepayments | kakaopay |
 * | O6 | vbank | tosspayments | null |
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/order-list-payment-method-filter.yaml
 */
class OrderListPaymentMethodAliasFilterTest extends ModuleTestCase
{
    private string $storagePath;

    private User $admin;

    /** @var array<string, Order> 시드 주문 (O1~O6) */
    private array $orders = [];

    /**
     * 카탈로그·설정·주문을 준비합니다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }

        $this->registerCatalog();
        $this->seedOrderSettings();
        $this->seedOrders();

        $this->admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
    }

    /**
     * 설정 파일·훅·해석기 캐시를 정리합니다.
     *
     * @return void 없음
     */
    protected function tearDown(): void
    {
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }
        HookManager::resetAll();
        app()->forgetInstance(PaymentMethodResolver::class);

        parent::tearDown();
    }

    /**
     * 토스·나이스 플러그인이 하는 것과 같이 결제수단 카탈로그와 PG 를 등록합니다.
     *
     * @return void 없음
     */
    private function registerCatalog(): void
    {
        $toss = fn (string $id, string $core, ?string $embedded) => [
            'id' => $id,
            'name' => ['ko' => $id, 'en' => $id],
            'description' => ['ko' => '', 'en' => ''],
            'icon' => 'wallet',
            'source' => 'plugin:sirsoft-tosspayments',
            'defaults' => array_filter([
                'pg_provider' => 'tosspayments',
                'pg_locked' => true,
                'needs_pg' => true,
                'refund_method' => 'pg',
                'is_active' => false,
                'min_order_amount' => 0,
                'stock_deduction_timing' => 'payment_complete',
                'core_payment_method' => $core,
                'embedded_pg_provider' => $embedded,
            ], fn ($v) => $v !== null),
        ];

        HookManager::addFilter('sirsoft-ecommerce.settings.filter_available_payment_methods', fn (array $methods) => array_merge($methods, [
            $toss('toss_card', 'card', null),
            $toss('toss_virtual_account', 'vbank', null),
            $toss('toss_kakaopay', 'card', '카카오페이'),
            $toss('toss_lpay', 'card', '엘페이'),
            [
                'id' => 'nicepay_kakaopay',
                'name' => ['ko' => '카카오페이 (나이스페이먼츠)', 'en' => 'KakaoPay (NicePayments)'],
                'description' => ['ko' => '', 'en' => ''],
                'icon' => 'wallet',
                'source' => 'plugin:sirsoft-pay_nicepayments',
                'defaults' => [
                    'pg_provider' => 'nicepayments',
                    'pg_locked' => true,
                    'needs_pg' => true,
                    'refund_method' => 'pg',
                    'is_active' => false,
                    'min_order_amount' => 0,
                    'stock_deduction_timing' => 'payment_complete',
                ],
            ],
        ]), 20);

        HookManager::addFilter('sirsoft-ecommerce.payment.registered_pg_providers', fn (array $providers) => array_merge($providers, [
            ['id' => 'tosspayments', 'name' => 'TossPayments'],
            ['id' => 'nicepayments', 'name' => 'NicePayments'],
        ]));
    }

    /**
     * 주문설정 저장 파일 — 엘페이 별칭만 비활성.
     *
     * @return void 없음
     */
    private function seedOrderSettings(): void
    {
        File::ensureDirectoryExists($this->storagePath);
        File::put($this->storagePath.'/order_settings.json', json_encode([
            'default_pg_provider' => 'tosspayments',
            'payment_methods' => [
                ['id' => 'card', 'is_active' => true, 'sort_order' => 1],
                ['id' => 'vbank', 'is_active' => true, 'sort_order' => 2],
                ['id' => 'toss_card', 'is_active' => true, 'sort_order' => 3],
                ['id' => 'toss_virtual_account', 'is_active' => true, 'sort_order' => 4],
                ['id' => 'toss_kakaopay', 'is_active' => true, 'sort_order' => 5],
                ['id' => 'toss_lpay', 'is_active' => false, 'sort_order' => 6],
                ['id' => 'nicepay_kakaopay', 'is_active' => true, 'sort_order' => 7],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        app(EcommerceSettingsService::class)->clearCache();
        app()->forgetInstance(PaymentMethodResolver::class);
    }

    /**
     * O1~O6 주문을 만듭니다.
     *
     * @return void 없음
     */
    private function seedOrders(): void
    {
        $rows = [
            'O1' => ['card', 'tosspayments', null],
            'O2' => ['card', 'tosspayments', '카카오페이'],
            'O3' => ['card', 'tosspayments', '엘페이'],
            'O4' => ['card', 'nicepayments', null],
            'O5' => ['nicepay_kakaopay', 'nicepayments', 'kakaopay'],
            'O6' => ['vbank', 'tosspayments', null],
        ];

        foreach ($rows as $key => [$method, $pg, $embedded]) {
            $order = OrderFactory::new()->create(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
            OrderPaymentFactory::new()->forOrder($order)->create([
                'payment_method' => $method,
                'pg_provider' => $pg,
                'embedded_pg_provider' => $embedded,
            ]);
            $this->orders[$key] = $order;
        }
    }

    /**
     * 관리자 주문 목록을 결제수단 필터로 조회해 시드 주문 키 집합을 돌려줍니다.
     *
     * @param  array<int, string>  $methods  결제수단 id
     * @param  array<string, mixed>  $extra  추가 쿼리
     * @return array<int, string> 시드 주문 키 (정렬)
     */
    private function listKeys(array $methods, array $extra = []): array
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders?'.http_build_query(array_merge([
                'payment_method' => $methods,
                'per_page' => 50,
            ], $extra)));

        $response->assertOk();

        return $this->keysOf(collect($response->json('data.data'))->pluck('order_number')->all());
    }

    /**
     * 주문번호 목록을 시드 주문 키로 바꿉니다.
     *
     * @param  array<int, string>  $orderNumbers  주문번호
     * @return array<int, string> 시드 주문 키 (정렬, 시드 밖 주문은 제외)
     */
    private function keysOf(array $orderNumbers): array
    {
        $keys = [];
        foreach ($this->orders as $key => $order) {
            if (in_array($order->order_number, $orderNumbers, true)) {
                $keys[] = $key;
            }
        }
        sort($keys);

        return $keys;
    }

    /**
     * 「신용카드」(card)는 활성 토스 간편결제 별칭이 찾는 주문(O2)을 뺀다 — 비활성 별칭(O3)·나이스 카드(O4)는 남는다.
     *
     * @scenario selected_kind=builtin, alias_active=true
     *
     * @effects core_filter_excludes_active_easy_pay_alias
     *
     * @return void 없음
     */
    public function test_core_card_excludes_active_toss_easy_pay_orders(): void
    {
        $this->assertSame(['O1', 'O3', 'O4'], $this->listKeys(['card']));
    }

    /**
     * 간편결제 별칭(toss_kakaopay)은 card + tosspayments + 카카오페이 주문을 찾는다.
     *
     * @scenario selected_kind=easy_pay_alias, alias_active=true
     *
     * @effects alias_filter_matches_core_pg_embedded
     *
     * @return void 없음
     */
    public function test_easy_pay_alias_filter_matches_its_orders(): void
    {
        $this->assertSame(['O2'], $this->listKeys(['toss_kakaopay']));
    }

    /**
     * 비활성 별칭(엘페이)의 주문은 선택지가 없으므로 「신용카드」에 남는다.
     *
     * @scenario selected_kind=builtin, alias_active=false
     *
     * @effects inactive_alias_rows_reachable_via_core
     *
     * @return void 없음
     */
    public function test_inactive_alias_orders_stay_under_core_card(): void
    {
        $this->assertContains('O3', $this->listKeys(['card']));
    }

    /**
     * 비간편결제 별칭(toss_card)은 토스 카드 주문 중 활성 간편결제 주문만 뺀다.
     *
     * @scenario selected_kind=plain_alias, alias_active=true
     *
     * @effects alias_filter_matches_core_pg_embedded, core_filter_excludes_active_easy_pay_alias
     *
     * @return void 없음
     */
    public function test_non_easy_pay_alias_excludes_active_easy_pay(): void
    {
        $this->assertSame(['O1', 'O3'], $this->listKeys(['toss_card']));
    }

    /**
     * 가상계좌 별칭(toss_virtual_account)은 vbank + tosspayments 주문을 찾는다.
     *
     * @scenario selected_kind=plain_alias, alias_active=true
     *
     * @effects alias_filter_matches_core_pg_embedded
     *
     * @return void 없음
     */
    public function test_vbank_alias(): void
    {
        $this->assertSame(['O6'], $this->listKeys(['toss_virtual_account']));
    }

    /**
     * 여러 선택지는 합집합이다.
     *
     * @scenario selected_kind=easy_pay_alias, alias_active=true
     *
     * @effects alias_filter_matches_core_pg_embedded, core_filter_excludes_active_easy_pay_alias
     *
     * @return void 없음
     */
    public function test_union_card_and_alias(): void
    {
        $this->assertSame(['O1', 'O2', 'O3', 'O4'], $this->listKeys(['card', 'toss_kakaopay']));
    }

    /**
     * 확장이 자기 id 로 저장하는 결제수단(nicepay_kakaopay)은 종전대로 그 id 로 찾는다.
     *
     * @scenario selected_kind=own_extension_id, alias_active=true
     *
     * @effects alias_filter_matches_core_pg_embedded
     *
     * @return void 없음
     */
    public function test_extension_own_id_unchanged(): void
    {
        $this->assertSame(['O5'], $this->listKeys(['nicepay_kakaopay']));
    }

    /**
     * 필터 인자로 들어온 내부 조건 키(_payment_method_clauses)는 버린다 — 바깥에서 조건을 주입할 수 없다.
     *
     * @scenario selected_kind=builtin, alias_active=true
     *
     * @effects core_filter_excludes_active_easy_pay_alias
     *
     * @return void 없음
     */
    public function test_injected_clauses_param_is_ignored(): void
    {
        $this->actingAs($this->admin);

        $injected = app(OrderService::class)->getList([
            'per_page' => 50,
            '_payment_method_clauses' => [['paymentMethod' => 'vbank']],
        ]);
        $this->assertSame(['O1', 'O2', 'O3', 'O4', 'O5', 'O6'], $this->keysOf($injected->getCollection()->pluck('order_number')->all()));

        $withAlias = app(OrderService::class)->getList([
            'per_page' => 50,
            'payment_method' => ['toss_kakaopay'],
            '_payment_method_clauses' => [['paymentMethod' => 'vbank']],
        ]);
        $this->assertSame(['O2'], $this->keysOf($withAlias->getCollection()->pluck('order_number')->all()));
    }

    /**
     * 엑셀 내보내기 원천 쿼리도 목록과 같은 결과다.
     *
     * @scenario selected_kind=easy_pay_alias, alias_active=true
     *
     * @effects export_matches_list
     *
     * @return void 없음
     */
    public function test_export_query_matches_list(): void
    {
        $ids = app(OrderExportType::class)
            ->query(['payment_method' => ['toss_kakaopay']], null, $this->admin, null)
            ->pluck('order_number')
            ->all();

        $this->assertSame(['O2'], $this->keysOf($ids));
        $this->assertSame($this->listKeys(['toss_kakaopay']), $this->keysOf($ids));

        $legacy = app(OrderService::class)->getForExport(['payment_method' => ['card']]);
        $this->assertSame(['O1', 'O3', 'O4'], $this->keysOf($legacy->pluck('order_number')->all()));
    }
}
