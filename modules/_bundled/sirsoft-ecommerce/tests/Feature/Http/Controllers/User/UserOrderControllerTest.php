<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * UserOrderController Feature 테스트
 *
 * 사용자 주문 생성, 목록 조회, 상세 조회 API를 테스트합니다.
 */
class UserOrderControllerTest extends ModuleTestCase
{
    protected string $cartKey;

    protected Product $product;

    protected ProductOption $productOption;

    /**
     * 주문 테스트가 공유하는 상품·옵션과 장바구니 키를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cartKey = Str::uuid()->toString();

        // 테스트 상품 생성
        $this->product = Product::create([
            'name' => ['ko' => '테스트 상품', 'en' => 'Test Product'],
            'product_code' => 'TEST-'.Str::random(8),
            'sku' => 'SKU-'.Str::random(8),
            'list_price' => 20000,
            'selling_price' => 15000,
            'currency_code' => 'KRW',
            'stock_quantity' => 100,
            'sales_status' => ProductSalesStatus::ON_SALE,
            'display_status' => ProductDisplayStatus::VISIBLE,
            'has_options' => true,
        ]);

        // 테스트 상품 옵션 생성
        $this->productOption = ProductOption::create([
            'product_id' => $this->product->id,
            'option_code' => 'OPT-'.Str::random(8),
            'option_values' => ['색상' => '검정', '사이즈' => 'M'],
            'option_name' => null,
            'sku' => 'SKU-'.Str::random(8),
            'price_adjustment' => 0,
            'stock_quantity' => 50,
            'safe_stock_quantity' => 5,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    /**
     * 기본 PG 제공자를 설정하고, 그 제공자를 레지스트리에도 등록합니다.
     *
     * 설정만 바꾸면 카탈로그가 그 PG 를 "사라진 PG"(`_orphaned_pg`)로 판정해 해당 결제수단이
     * 주문 불가가 되고 주문 생성이 422 로 막힙니다(#570 고아 카탈로그 차단). 실제 운영에서는
     * PG 플러그인이 이 훅으로 자신을 등록하므로, 테스트도 같은 경로로 등록해야 합니다.
     *
     * @param  string  $providerId  PG 제공자 식별자
     * @return void 반환값 없음
     */
    protected function registerDefaultPgProvider(string $providerId): void
    {
        app(EcommerceSettingsService::class)->setSetting('order_settings.default_pg_provider', $providerId);

        HookManager::addFilter(
            'sirsoft-ecommerce.payment.registered_pg_providers',
            fn (array $providers) => array_merge($providers, [[
                'id' => $providerId,
                'name' => $providerId,
                'payment_handler' => 'sirsoft-pay_'.$providerId.'.requestPayment',
            ]])
        );

        app(PaymentMethodResolver::class)->flushCache();
        app(EcommerceSettingsService::class)->clearCache();
    }

    /**
     * 임시 주문(주문서) 생성 헬퍼 — 상품 1종 수량 2, 결제예정금액 30,000원.
     *
     * @param  int  $userId  주문서를 소유할 회원 ID
     * @return TempOrder 생성된 임시 주문
     */
    protected function createTempOrder(int $userId): TempOrder
    {
        return TempOrder::create([
            'user_id' => $userId,
            'cart_key' => $this->cartKey,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_option_id' => $this->productOption->id,
                    'quantity' => 2,
                ],
            ],
            'calculation_result' => [
                'items' => [
                    [
                        'product_id' => $this->product->id,
                        'product_option_id' => $this->productOption->id,
                        'quantity' => 2,
                        'unit_price' => 15000,
                        'subtotal' => 30000,
                        'final_amount' => 30000,
                    ],
                ],
                'summary' => [
                    'subtotal' => 30000,
                    'total_discount' => 0,
                    'total_shipping' => 0,
                    'payment_amount' => 30000,
                    'final_amount' => 30000,
                ],
            ],
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    /**
     * 주문 생성 기본 테스트 (무통장입금)
     *
     * @scenario area=create
     *
     * @effects mypage_order_create_succeeds_for_member
     *
     * @return void 반환값 없음
     */
    public function test_로그인_사용자_주문_생성_성공(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            [
                'orderer' => [
                    'name' => '홍길동',
                    'phone' => '010-1234-5678',
                    'email' => 'hong@test.com',
                ],
                'shipping' => [
                    'recipient_name' => '김철수',
                    'recipient_phone' => '010-9876-5432',
                    'country_code' => 'KR',
                    'zipcode' => '12345',
                    'address' => '서울시 강남구 테헤란로 123',
                    'address_detail' => '101동 1001호',
                ],
                'shipping_memo' => '문 앞에 놓아주세요',
                'payment_method' => PaymentMethodEnum::DBANK->value,
                'expected_total_amount' => 30000,
                'depositor_name' => '홍길동',
                'dbank' => [
                    'bank_code' => 'KB',
                    'bank_name' => '국민은행',
                    'account_number' => '123-456-789012',
                    'account_holder' => '주식회사 테스트',
                ],
            ],
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'order' => [
                        'id',
                        'order_number',
                        'order_status',
                        'total_amount',
                    ],
                ],
            ]);
    }

    /**
     * 임시 주문 없이 주문 생성 실패 테스트
     *
     * @scenario area=create
     *
     * @effects mypage_order_create_requires_temp_order
     *
     * @return void 반환값 없음
     */
    public function test_임시주문_없으면_주문_생성_실패(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            [
                'orderer' => [
                    'name' => '홍길동',
                    'phone' => '010-1234-5678',
                    'email' => 'hong@test.com',
                ],
                'shipping' => [
                    'recipient_name' => '김철수',
                    'recipient_phone' => '010-9876-5432',
                    'country_code' => 'KR',
                    'zipcode' => '12345',
                    'address' => '서울시 강남구',
                    'address_detail' => '101동',
                ],
                'payment_method' => PaymentMethodEnum::DBANK->value,
                'expected_total_amount' => 30000,
                'depositor_name' => '홍길동',
                'dbank' => [
                    'bank_code' => 'KB',
                    'bank_name' => '국민은행',
                    'account_number' => '123-456-789012',
                    'account_holder' => '주식회사 테스트',
                ],
            ],
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(404);
    }

    /**
     * 만료된 임시 주문으로 생성 실패 테스트
     *
     * @scenario area=create
     *
     * @effects mypage_order_create_rejects_expired_temp_order
     *
     * @return void 반환값 없음
     */
    public function test_만료된_임시주문으로_주문_생성_실패(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $tempOrder = $this->createTempOrder($user->id);
        $tempOrder->update(['expires_at' => now()->subMinute()]);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            [
                'orderer' => [
                    'name' => '홍길동',
                    'phone' => '010-1234-5678',
                    'email' => 'hong@test.com',
                ],
                'shipping' => [
                    'recipient_name' => '김철수',
                    'recipient_phone' => '010-9876-5432',
                    'country_code' => 'KR',
                    'zipcode' => '12345',
                    'address' => '서울시 강남구',
                    'address_detail' => '101동',
                ],
                'payment_method' => PaymentMethodEnum::DBANK->value,
                'expected_total_amount' => 30000,
                'depositor_name' => '홍길동',
                'dbank' => [
                    'bank_code' => 'KB',
                    'bank_name' => '국민은행',
                    'account_number' => '123-456-789012',
                    'account_holder' => '주식회사 테스트',
                ],
            ],
            ['X-Cart-Key' => $this->cartKey]
        );

        // 만료된 임시주문은 findValidByUserOrCartKey에서 null 반환하므로 404
        $response->assertStatus(404);
    }

    /**
     * 결제 금액 불일치 시 실패 테스트
     *
     * @scenario area=create
     *
     * @effects mypage_order_create_rejects_amount_mismatch
     *
     * @return void 반환값 없음
     */
    public function test_결제금액_불일치_시_주문_생성_실패(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            [
                'orderer_name' => '홍길동',
                'orderer_phone' => '010-1234-5678',
                'orderer_email' => 'hong@test.com',
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9876-5432',
                'zipcode' => '12345',
                'address1' => '서울시 강남구',
                'address2' => '101동',
                'country_code' => 'KR',
                'payment_method' => PaymentMethodEnum::DBANK->value,
                'expected_total_amount' => 99999, // 잘못된 금액
            ],
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(422);
    }

    /**
     * 비로그인 사용자도 주문 생성 endpoint 진입 가능 (인증으로 막지 않음)
     *
     * POST user/orders 가 회원/비회원 공유 단일 endpoint(optional.sanctum)로 통합됨.
     * 따라서 비로그인 요청은 401 로 차단되지 않고 검증 단계까지 진입한다. 본 테스트는
     * 잘못된 body(루트 레벨 키, 비회원 필수값 누락)로 401 이 아닌 422(검증 실패)가 나는지 확인해
     * 인증 차단이 아닌 검증 차단임을 보장한다.
     *
     * @scenario area=create
     *
     * @effects mypage_order_create_is_gated_by_validation_not_auth
     *
     * @return void 반환값 없음
     */
    public function test_비로그인_주문_생성은_인증이_아니라_검증으로_처리된다(): void
    {
        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            [
                'orderer_name' => '홍길동',
                'orderer_phone' => '010-1234-5678',
                'orderer_email' => 'hong@test.com',
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9876-5432',
                'zipcode' => '12345',
                'address1' => '서울시 강남구',
                'address2' => '101동',
                'country_code' => 'KR',
                'payment_method' => PaymentMethodEnum::DBANK->value,
                'expected_total_amount' => 30000,
            ],
            ['X-Cart-Key' => $this->cartKey]
        );

        // 401(인증 차단) 이 아니라 422(검증 실패) — 비회원도 endpoint 에 진입함
        $response->assertStatus(422);
    }

    // ========================================================================
    // 주문 목록 조회 (index) 테스트
    // ========================================================================

    /**
     * 인증된 사용자의 본인 주문 목록 조회 성공
     *
     * @scenario area=list
     *
     * @effects mypage_order_list_returns_own_orders_with_statistics
     *
     * @return void 반환값 없음
     */
    public function test_인증된_사용자_주문_목록_조회_성공(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        // 본인 주문 3건 생성
        Order::factory()->count(3)->forUser($user)->pendingPayment()->create();

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'data',
                    'statistics' => [
                        'pending_payment',
                        'payment_complete',
                        'preparing',
                        'shipping',
                        'delivered',
                        'confirmed',
                    ],
                    'pagination' => [
                        'current_page',
                        'last_page',
                        'per_page',
                        'total',
                    ],
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pagination.total', 3);
    }

    /**
     * 기본 목록은 대표 아이템 1건 + 전체 개수만 싣는다.
     *
     * 주문마다 아이템을 전부 싣는 것이 기본이면, 그 값을 그리지 않는 호출자(모바일 목록,
     * 외부 연동)까지 한 페이지를 여는 것만으로 주문 수 × 아이템 수를 받는다. 전량이 필요한
     * 화면은 `?with_items=1` 로 켠다.
     *
     * @scenario surface=my_page_list,option_profile=multiple
     *
     * @effects my_page_list_default_is_representative_only
     *
     * @return void 반환값 없음
     */
    public function test_기본_목록은_대표_아이템_1건과_개수만_싣는다(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $order = Order::factory()->forUser($user)->pendingPayment()->create();
        OrderOption::factory()->count(3)->create(['order_id' => $order->id]);

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $response->assertStatus(200);

        $row = collect($response->json('data.data'))->firstWhere('id', $order->id);

        $this->assertNotNull($row, '생성한 주문이 목록에 있어야 한다');
        $this->assertIsArray($row['items'] ?? null, '대표 1건도 배열 형태로 실어야 화면 순회가 깨지지 않는다');
        $this->assertCount(1, $row['items'], '기본은 대표 1건이다');
        $this->assertSame(3, $row['item_count'], '"외 N건" 을 그리려면 전체 개수가 필요하다');
    }

    /**
     * `?with_items=1` 은 전량이 필요한 화면(마이페이지 주문내역)을 위한 경로다.
     *
     * 화면(`partials/mypage/orders/_list.json`)이 `order.items` 를 순회하므로, 이 경로가
     * 깨지면 주문마다 상품 한 줄만 남는다.
     *
     * @scenario surface=my_page_list,option_profile=multiple
     *
     * @effects my_page_list_enumerates_every_item_when_requested
     *
     * @return void 반환값 없음
     */
    public function test_with_items_1_이면_아이템을_전부_싣는다(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $order = Order::factory()->forUser($user)->pendingPayment()->create();
        OrderOption::factory()->count(3)->create(['order_id' => $order->id]);

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders?with_items=1');

        $response->assertStatus(200);

        $row = collect($response->json('data.data'))->firstWhere('id', $order->id);

        $this->assertNotNull($row);
        $this->assertCount(3, $row['items'], 'with_items=1 이면 전량이다');
        $this->assertSame(3, $row['item_count']);
    }

    /**
     * 아이템 조회 쿼리 수가 주문 수에 비례하지 않는다.
     *
     * 대표 1건 축약이 관계 재조회로 구현되면 목록 한 페이지가 주문 수만큼 쿼리를 낸다 —
     * 페이로드를 줄이려다 쿼리를 늘리는 맞바꿈이 된다.
     *
     * @scenario surface=my_page_list,option_profile=multiple
     *
     * @effects my_page_list_option_query_count_is_constant
     *
     * @return void 반환값 없음
     */
    public function test_아이템_조회_쿼리수가_주문수에_비례하지_않는다(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $makeOrder = function () use ($user) {
            $order = Order::factory()->forUser($user)->pendingPayment()->create();
            OrderOption::factory()->count(3)->create(['order_id' => $order->id]);
        };

        $makeOrder();

        // 권한/설정 캐시를 채워 측정에서 제외
        $this->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $measure = function (): int {
            $count = 0;
            DB::listen(function ($query) use (&$count) {
                if (str_contains($query->sql, 'ecommerce_order_options')) {
                    $count++;
                }
            });

            $this->getJson('/api/modules/sirsoft-ecommerce/user/orders')->assertStatus(200);

            return $count;
        };

        $withOne = $measure();

        for ($i = 0; $i < 4; $i++) {
            $makeOrder();
        }

        $withFive = $measure();

        $this->assertSame(
            $withOne,
            $withFive,
            "주문 1건일 때 {$withOne}회, 5건일 때 {$withFive}회 — 행 수에 비례하면 N+1 이다"
        );
    }

    /**
     * 해석할 수 없는 with_items 값은 조용히 무시하지 않고 422 로 돌려준다.
     *
     * null 로 정규화하면 오타 파라미터가 "미지정" 으로 통과해 호출자가 잘못을 알 수 없다.
     *
     * @scenario surface=my_page_list,option_profile=multiple
     *
     * @effects my_page_list_rejects_unparseable_with_items
     *
     * @return void 반환값 없음
     */
    public function test_with_items_에_해석불가한_값이_오면_422(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $this->getJson('/api/modules/sirsoft-ecommerce/user/orders?with_items=maybe')
            ->assertStatus(422)
            ->assertJsonValidationErrors('with_items');
    }

    /**
     * 현재 로케일 키가 없는 다국어(array) product_option_name 옵션이 포함된 주문도
     * 500 없이 직렬화된다 (UserOrderListResource 의 reset(매직속성) 회귀).
     *
     * @scenario area=list
     *
     * @effects mypage_order_list_serializes_localized_option_name
     *
     * @return void 반환값 없음
     */
    public function test_주문_목록_array_옵션명_직렬화_오류없음(): void
    {
        app()->setLocale('en');
        $user = $this->createUser();
        $this->actingAs($user);

        $order = Order::factory()->forUser($user)->pendingPayment()->create();
        OrderOption::factory()->forOrder($order)->create([
            'product_option_name' => ['ko' => '레드 / L'],
        ]);

        // 운영처럼 E_NOTICE("Indirect modification of overloaded property") 를 ErrorException 으로 승격
        $previous = set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if (! (error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders');
        } finally {
            set_error_handler($previous);
        }

        $response->assertStatus(200);
        $this->assertSame('레드 / L', $response->json('data.data.0.items.0.product_option_name'));
    }

    /**
     * 주문 목록에 타인의 주문이 포함되지 않음
     *
     * @scenario area=list
     *
     * @effects mypage_order_list_excludes_other_members_orders
     *
     * @return void 반환값 없음
     */
    public function test_주문_목록에_타인_주문_미포함(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser();
        $this->actingAs($user);

        // 본인 주문 2건
        Order::factory()->count(2)->forUser($user)->pendingPayment()->create();
        // 타인 주문 3건
        Order::factory()->count(3)->forUser($otherUser)->paid()->create();

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $response->assertStatus(200)
            ->assertJsonPath('data.pagination.total', 2);
    }

    /**
     * 상태 필터로 주문 목록 조회
     *
     * @scenario area=list
     *
     * @effects mypage_order_list_filters_by_status
     *
     * @return void 반환값 없음
     */
    public function test_상태_필터로_주문_목록_조회(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        // 다양한 상태의 주문 생성
        Order::factory()->count(2)->forUser($user)->pendingPayment()->create();
        Order::factory()->count(3)->forUser($user)->paid()->create();
        Order::factory()->forUser($user)->shipping()->create();

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders?status=payment_complete');

        $response->assertStatus(200)
            ->assertJsonPath('data.pagination.total', 3);
    }

    /**
     * 주문 목록 페이지네이션 동작
     *
     * @scenario area=list
     *
     * @effects mypage_order_list_paginates
     *
     * @return void 반환값 없음
     */
    public function test_주문_목록_페이지네이션(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        Order::factory()->count(15)->forUser($user)->pendingPayment()->create();

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders?per_page=5&page=2');

        $response->assertStatus(200)
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.per_page', 5)
            ->assertJsonPath('data.pagination.total', 15);
    }

    /**
     * 주문 목록 pagination 은 `paginationMeta()` 표준 계약을 싣는다.
     *
     * 손조립 블록은 `from`/`to`/`has_more_pages` 를 빠뜨렸고, 저장소가 상한형으로 바뀌면
     * `total_relation`/`total_is_exact` 까지 통째로 빠져 잘린 총 건수가 정확한 값처럼 나간다.
     * 기존 4키는 그대로 남으므로 이 단언은 계약 확대만 고정한다.
     *
     * @scenario area=list
     *
     * @effects mypage_order_list_pagination_meta_is_standard
     *
     * @return void 반환값 없음
     */
    public function test_주문_목록_페이지네이션_표준_계약(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        Order::factory()->count(15)->forUser($user)->pendingPayment()->create();

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders?per_page=5&page=2');

        $response->assertStatus(200)
            // 기존 4키 보존
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.last_page', 3)
            ->assertJsonPath('data.pagination.per_page', 5)
            ->assertJsonPath('data.pagination.total', 15)
            // 손조립이 빠뜨렸던 3키
            ->assertJsonPath('data.pagination.from', 6)
            ->assertJsonPath('data.pagination.to', 10)
            ->assertJsonPath('data.pagination.has_more_pages', true);
    }

    /**
     * 주문 목록에 상태별 통계가 정확하게 포함됨
     *
     * @scenario area=list
     *
     * @effects mypage_order_list_statistics_count_each_status
     *
     * @return void 반환값 없음
     */
    public function test_주문_목록_통계_정확성(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        Order::factory()->count(2)->forUser($user)->pendingPayment()->create();
        Order::factory()->count(1)->forUser($user)->paid()->create();
        Order::factory()->count(3)->forUser($user)->shipping()->create();
        Order::factory()->count(1)->forUser($user)->delivered()->create();
        Order::factory()->count(1)->forUser($user)->confirmed()->create();

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $response->assertStatus(200)
            ->assertJsonPath('data.statistics.pending_payment', 2)
            ->assertJsonPath('data.statistics.payment_complete', 1)
            ->assertJsonPath('data.statistics.shipping', 3)
            ->assertJsonPath('data.statistics.delivered', 1)
            ->assertJsonPath('data.statistics.confirmed', 1);
    }

    /**
     * 비로그인 사용자 주문 목록 조회 시 401
     *
     * @scenario area=list
     *
     * @effects mypage_order_list_requires_login
     *
     * @return void 반환값 없음
     */
    public function test_비로그인_사용자_주문_목록_조회_실패(): void
    {
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $response->assertStatus(401);
    }

    /**
     * 주문 목록 각 항목의 리소스 구조 확인
     *
     * @scenario area=list
     *
     * @effects mypage_order_list_row_shape_is_fixed
     *
     * @return void 반환값 없음
     */
    public function test_주문_목록_리소스_구조(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $order = Order::factory()->forUser($user)->pendingPayment()->create();
        OrderOption::factory()->forOrder($order)->create([
            'product_id' => $this->product->id,
            'product_option_id' => $this->productOption->id,
        ]);

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'order_number',
                            'status',
                            'status_label',
                            'status_variant',
                            'ordered_at',
                            'ordered_at_formatted',
                            'total_amount',
                            'total_amount_formatted',
                            'items',
                            'item_count',
                        ],
                    ],
                ],
            ]);
    }

    // ========================================================================
    // 주문 상세 조회 (show by ID) 테스트
    // ========================================================================

    /**
     * 인증된 사용자의 본인 주문 상세 조회 성공 (ID 기반)
     *
     * @scenario area=show
     *
     * @effects mypage_order_show_returns_own_order
     *
     * @return void 반환값 없음
     */
    public function test_본인_주문_상세_조회_성공(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $order = Order::factory()->forUser($user)->pendingPayment()->create();

        $response = $this->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'order_number',
                    'order_status',
                    'total_amount',
                ],
            ])
            ->assertJsonPath('data.id', $order->id);
    }

    /**
     * 타인의 주문 상세 조회 시 404
     *
     * @scenario area=show
     *
     * @effects mypage_order_show_hides_other_members_order
     *
     * @return void 반환값 없음
     */
    public function test_타인_주문_상세_조회_실패(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser();
        $this->actingAs($user);

        $order = Order::factory()->forUser($otherUser)->pendingPayment()->create();

        $response = $this->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}");

        $response->assertStatus(404);
    }

    /**
     * 존재하지 않는 주문 ID로 상세 조회 시 404
     *
     * @scenario area=show
     *
     * @effects mypage_order_show_unknown_id_is_not_found
     *
     * @return void 반환값 없음
     */
    public function test_존재하지_않는_주문_상세_조회_실패(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders/99999');

        $response->assertStatus(404);
    }

    /**
     * 비로그인 사용자 주문 상세 조회 시 401
     *
     * @scenario area=show
     *
     * @effects mypage_order_show_requires_login
     *
     * @return void 반환값 없음
     */
    public function test_비로그인_사용자_주문_상세_조회_실패(): void
    {
        $order = Order::factory()->pendingPayment()->create();

        $response = $this->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}");

        $response->assertStatus(401);
    }

    // ========================================================================
    // 결제 취소 기록 (cancelPayment) 테스트
    // ========================================================================

    /**
     * pending_order 상태 주문에 결제 취소 기록 성공
     *
     * @scenario area=cancel_record
     *
     * @effects order_cancel_payment_records_history
     *
     * @return void 반환값 없음
     */
    public function test_결제_취소_기록_성공(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $order = Order::factory()->forUser($user)->create([
            'order_status' => OrderStatusEnum::PENDING_ORDER,
        ]);
        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
        ]);

        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/orders/{$order->order_number}/cancel-payment",
            [
                'cancel_code' => 'USER_CANCEL',
                'cancel_message' => '사용자가 결제를 취소했습니다.',
            ]
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // cancel_history 기록 확인
        $payment = $order->fresh()->payment;
        $this->assertEquals(PaymentStatusEnum::CANCELLED->value, $payment->payment_status->value);
        $this->assertNotEmpty($payment->cancel_history);
        $this->assertEquals('USER_CANCEL', $payment->cancel_history[0]['cancel_code']);
        $this->assertEquals('사용자가 결제를 취소했습니다.', $payment->cancel_history[0]['cancel_message']);
    }

    /**
     * 타인의 주문에 결제 취소 기록 시 404
     *
     * @scenario area=cancel_record
     *
     * @effects order_cancel_payment_hides_other_members_order
     *
     * @return void 반환값 없음
     */
    public function test_타인_주문_결제_취소_기록_실패(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser();
        $this->actingAs($user);

        $order = Order::factory()->forUser($otherUser)->create([
            'order_status' => OrderStatusEnum::PENDING_ORDER,
        ]);

        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/orders/{$order->order_number}/cancel-payment"
        );

        $response->assertStatus(404);
    }

    /**
     * pending_order 외 상태에서 결제 취소 기록 시 422
     *
     * @scenario area=cancel_record
     *
     * @effects order_cancel_payment_rejects_non_pending_status
     *
     * @return void 반환값 없음
     */
    public function test_결제완료_상태에서_결제_취소_기록_실패(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $order = Order::factory()->forUser($user)->paid()->create();

        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/orders/{$order->order_number}/cancel-payment"
        );

        $response->assertStatus(422);
    }

    /**
     * 비로그인 사용자가 유효한 게스트 조회 토큰으로 비회원 주문 결제 취소 기록 성공
     *
     * 주문번호만으로는 통과하지 않는다 — 보호된 게스트 경로와 동일하게
     * X-Guest-Order-Token 이 필요하다 (토큰 부재 차단은 GuestCancelPaymentAuthTest).
     *
     * @scenario area=cancel_record
     *
     * @effects order_cancel_payment_allows_guest_with_token
     *
     * @return void 반환값 없음
     */
    public function test_비로그인_사용자_비회원_주문_결제_취소_기록_성공(): void
    {
        $guestPassword = 'guest12';
        $guestPhone = '010-1234-5678';

        // 비회원 주문 (user_id = null)
        $order = Order::factory()->create([
            'user_id' => null,
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'guest_lookup_password_hash' => Hash::make($guestPassword),
        ]);
        OrderAddress::factory()->shipping()->forOrder($order)->create([
            'orderer_phone' => $guestPhone,
        ]);
        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
        ]);

        $token = app(GuestOrderAuthService::class)
            ->authenticate($order->order_number, $guestPhone, $guestPassword, '10.0.0.1')['token'];

        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/orders/{$order->order_number}/cancel-payment",
            [],
            ['X-Guest-Order-Token' => $token]
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $payment = $order->fresh()->payment;
        $this->assertEquals(PaymentStatusEnum::CANCELLED->value, $payment->payment_status->value);
    }

    /**
     * 비로그인 사용자가 회원 주문에 접근 시 404
     *
     * @scenario area=cancel_record
     *
     * @effects order_cancel_payment_guest_cannot_touch_member_order
     *
     * @return void 반환값 없음
     */
    public function test_비로그인_사용자_회원_주문_결제_취소_기록_실패(): void
    {
        $user = $this->createUser();

        // 회원 주문 (user_id 존재)
        $order = Order::factory()->forUser($user)->create([
            'order_status' => OrderStatusEnum::PENDING_ORDER,
        ]);

        // 비로그인 상태에서 회원 주문 접근
        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/orders/{$order->order_number}/cancel-payment"
        );

        $response->assertStatus(404);
    }

    /**
     * 존재하지 않는 주문번호로 결제 취소 기록 시 404
     *
     * @scenario area=cancel_record
     *
     * @effects order_cancel_payment_unknown_number_is_not_found
     *
     * @return void 반환값 없음
     */
    public function test_존재하지_않는_주문_결제_취소_기록_실패(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/orders/NON_EXISTENT/cancel-payment'
        );

        $response->assertStatus(404);
    }

    // ========================================================================
    // 배송지 자동 저장 테스트
    // ========================================================================

    /**
     * 주문 생성 요청 본문 헬퍼 (배송지 저장 테스트용).
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 필드 (결제수단·배송지 저장 플래그 등)
     * @return array<string, mixed> 주문 생성 요청 본문
     */
    protected function buildOrderPayload(array $overrides = []): array
    {
        return array_merge([
            'orderer' => [
                'name' => '홍길동',
                'phone' => '010-1234-5678',
                'email' => 'hong@test.com',
            ],
            'shipping' => [
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9876-5432',
                'country_code' => 'KR',
                'zipcode' => '12345',
                'address' => '서울시 강남구 테헤란로 123',
                'address_detail' => '101동 1001호',
            ],
            'shipping_memo' => '문 앞에 놓아주세요',
            'payment_method' => PaymentMethodEnum::DBANK->value,
            'expected_total_amount' => 30000,
            'depositor_name' => '홍길동',
            'dbank' => [
                'bank_code' => 'KB',
                'bank_name' => '국민은행',
                'account_number' => '123-456-789012',
                'account_holder' => '주식회사 테스트',
            ],
        ], $overrides);
    }

    /**
     * 비PG 결제(무통장)는 배송지 저장 체크가 켜져 있으면 주문 생성 시점에 곧바로 주소록에 남긴다.
     *
     * @scenario area=address_save
     *
     * @effects order_create_saves_address_when_checked
     *
     * @return void 반환값 없음
     */
    public function test_비_p_g_체크_o_n_배송지_생성(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload(['save_shipping_address' => true]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        $this->assertDatabaseHas('ecommerce_user_addresses', [
            'user_id' => $user->id,
            'recipient_name' => '김철수',
            'zipcode' => '12345',
            'address' => '서울시 강남구 테헤란로 123',
        ]);
    }

    /**
     * 체크를 끄면 주소록에 아무 것도 남지 않는다 — 묻지 않고 저장하면 주소록이 주문 수만큼 불어난다.
     *
     * @scenario area=address_save
     *
     * @effects order_create_skips_address_when_unchecked
     *
     * @return void 반환값 없음
     */
    public function test_비_p_g_체크_of_f_배송지_미생성(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload(['save_shipping_address' => false]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        $this->assertDatabaseMissing('ecommerce_user_addresses', [
            'user_id' => $user->id,
        ]);
    }

    /**
     * 플래그 자체가 오지 않으면 「저장 안 함」이다 — 미지정을 켬으로 해석하지 않는다.
     *
     * @scenario area=address_save
     *
     * @effects order_create_skips_address_when_flag_absent
     *
     * @return void 반환값 없음
     */
    public function test_비_p_g_체크_미전달_배송지_미생성(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload(),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        $this->assertDatabaseMissing('ecommerce_user_addresses', [
            'user_id' => $user->id,
        ]);
    }

    /**
     * PG 결제는 결제가 끝나야 주소를 남긴다 — 주문 생성 시점에는 order_meta 에 표식만 둔다.
     *
     * @scenario area=address_save
     *
     * @effects order_create_defers_address_save_for_pg
     *
     * @return void 반환값 없음
     */
    public function test_p_g_체크_o_n_order_meta에_플래그_저장(): void
    {
        // PG 결제가 실제로 동작하도록 기본 PG 제공자 설정
        $this->registerDefaultPgProvider('tosspayments');

        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload([
                'payment_method' => PaymentMethodEnum::CARD->value,
                'save_shipping_address' => true,
                'depositor_name' => null,
                'dbank' => null,
            ]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        $order = Order::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertTrue($order->order_meta['save_shipping_address'] ?? false);
        $this->assertArrayHasKey('shipping_info_for_save', $order->order_meta);
    }

    /**
     * PG 결제 주문이 «생성만» 된 시점에는 주소록이 비어 있다.
     *
     * 결제를 중간에 그만둔 주문의 배송지가 주소록에 남으면, 사지도 않은 주소가 다음 주문서에 뜬다.
     *
     * @scenario area=address_save
     *
     * @effects order_create_does_not_save_address_before_pg_completes
     *
     * @return void 반환값 없음
     */
    public function test_p_g_체크_o_n_user_address_미생성(): void
    {
        $this->registerDefaultPgProvider('tosspayments');

        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload([
                'payment_method' => PaymentMethodEnum::CARD->value,
                'save_shipping_address' => true,
                'depositor_name' => null,
                'dbank' => null,
            ]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        $this->assertDatabaseMissing('ecommerce_user_addresses', [
            'user_id' => $user->id,
        ]);
    }

    /**
     * PG 결제라도 체크가 꺼져 있으면 order_meta 에 표식을 남기지 않는다 — 결제완료 때 저장이 돌지 않는다.
     *
     * @scenario area=address_save
     *
     * @effects order_create_leaves_no_address_flag_when_unchecked
     *
     * @return void 반환값 없음
     */
    public function test_p_g_체크_of_f_order_meta_미저장(): void
    {
        $this->registerDefaultPgProvider('tosspayments');

        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload([
                'payment_method' => PaymentMethodEnum::CARD->value,
                'save_shipping_address' => false,
                'depositor_name' => null,
                'dbank' => null,
            ]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        $order = Order::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertFalse($order->order_meta['save_shipping_address'] ?? false);
    }

    /**
     * 주소록이 비어 있던 회원의 첫 배송지는 기본 배송지가 된다 — 기본이 없으면 다음 주문서가 채울 것을 고르지 못한다.
     *
     * @scenario area=address_save
     *
     * @effects order_create_marks_first_saved_address_as_default
     *
     * @return void 반환값 없음
     */
    public function test_첫_배송지_기본_배송지_자동_설정(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload(['save_shipping_address' => true]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        $address = UserAddress::where('user_id', $user->id)->first();
        $this->assertNotNull($address);
        $this->assertTrue($address->is_default);
    }

    /**
     * 자동 저장 이름이 이미 있으면 뒤에 순번을 붙인다 — 같은 이름이 겹치면 어느 것이 무엇인지 알 수 없다.
     *
     * @scenario area=address_save
     *
     * @effects order_create_numbers_auto_saved_address_names
     *
     * @return void 반환값 없음
     */
    public function test_배송지명_자동_순번_부여(): void
    {
        // auto_saved_label 은 locale 의존 (__() 호출 시점마다 다를 수 있음)이므로
        // 테스트 전체에서 locale 을 명시적으로 'ko' 로 고정
        app()->setLocale('ko');

        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        // 기존 '새 배송지' 생성 — 컨트롤러가 동일 locale 로 호출하도록 보장
        $autoSavedLabel = __('sirsoft-ecommerce::messages.address.auto_saved_label');
        UserAddress::factory()->create([
            'user_id' => $user->id,
            'name' => $autoSavedLabel,
        ]);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload(['save_shipping_address' => true]),
            ['X-Cart-Key' => $this->cartKey, 'Accept-Language' => 'ko']
        );

        $response->assertStatus(201);

        $expectedName = "{$autoSavedLabel} (2)";
        $newAddress = UserAddress::where('user_id', $user->id)
            ->where('name', $expectedName)
            ->first();
        $this->assertNotNull($newAddress, "배송지명 '{$expectedName}'이 생성되어야 합니다");
    }

    /**
     * 주문서에서 저장된 배송지를 고른 뒤 그 자리에서 고쳤으면 그 행을 갱신한다.
     *
     * 새 행이 쌓이면 다음 주문서도 옛 주소로 열려 같은 손질을 매번 반복하게 된다(2026-09-21 요구사항).
     *
     * @return void 반환값 없음
     *
     * @scenario area=address_save
     *
     * @effects order_create_updates_edited_saved_address
     *
     * @return void 반환값 없음
     */
    public function test_주문서에서_고친_배송지는_그_행을_갱신한다(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $existing = UserAddress::factory()->create([
            'user_id' => $user->id,
            'name' => '집',
            'recipient_name' => '옛수령인',
            'zipcode' => '00000',
            'address' => '옛 주소',
        ]);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload([
                'save_shipping_address' => true,
                'save_shipping_address_id' => $existing->id,
            ]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        $this->assertSame(1, UserAddress::where('user_id', $user->id)->count(), '새 행이 쌓이면 안 된다');

        $existing->refresh();
        $this->assertSame('김철수', $existing->recipient_name);
        $this->assertSame('12345', $existing->zipcode);
        // 구매자가 붙여 둔 배송지명은 건드리지 않는다 — 목록에서 어느 것이 무엇인지 알아야 한다.
        $this->assertSame('집', $existing->name);
    }

    /**
     * 소유자가 아닌 주소록 행 ID 가 실려 와도 그 행은 바뀌지 않고, 새로 만들지도 않는다.
     *
     * 잘못 실린 ID 가 「그럼 추가」 로 조용히 성공하면 드러날 자리가 영영 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario area=address_save
     *
     * @effects order_create_ignores_foreign_address_id
     *
     * @return void 반환값 없음
     */
    public function test_남의_배송지_i_d_로는_아무_것도_바뀌지_않는다(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $foreign = UserAddress::factory()->create([
            'user_id' => $other->id,
            'recipient_name' => '남의수령인',
        ]);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload([
                'save_shipping_address' => true,
                'save_shipping_address_id' => $foreign->id,
            ]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        $foreign->refresh();
        $this->assertSame('남의수령인', $foreign->recipient_name);
        // 갱신 대상이 없다고 해서 새로 만들지도 않는다 — 잘못 실린 ID 가 조용히 성공하면 안 된다.
        $this->assertSame(0, UserAddress::where('user_id', $user->id)->count());
    }

    /**
     * PG 결제는 결제완료 시점에 저장하므로 갱신 대상 행 ID 도 order_meta 에 함께 보관한다.
     *
     * @return void 반환값 없음
     *
     * @scenario area=address_save
     *
     * @effects order_create_defers_address_update_id_for_pg
     *
     * @return void 반환값 없음
     */
    public function test_p_g_결제는_갱신_대상_배송지_i_d_도_order_meta_에_남긴다(): void
    {
        $this->registerDefaultPgProvider('tosspayments');

        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $existing = UserAddress::factory()->create(['user_id' => $user->id]);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload([
                'payment_method' => PaymentMethodEnum::CARD->value,
                'save_shipping_address' => true,
                'save_shipping_address_id' => $existing->id,
                'depositor_name' => null,
                'dbank' => null,
            ]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);

        $order = Order::where('user_id', $user->id)->latest()->first();
        $this->assertSame($existing->id, $order->order_meta['save_shipping_address_id'] ?? null);
    }

    // ===== 장바구니 처리 통합 테스트 =====

    /**
     * cart_id를 포함한 임시 주문 생성 헬퍼
     *
     * @param  int  $userId  사용자 ID
     * @param  array<int, array<string, int>>  $cartItems  장바구니 줄 목록 (cart_id·product_id·product_option_id·quantity)
     * @return TempOrder 생성된 임시 주문
     */
    protected function createTempOrderWithCartIds(int $userId, array $cartItems): TempOrder
    {
        $items = [];
        $calcItems = [];
        $subtotal = 0;

        foreach ($cartItems as $item) {
            $unitPrice = 15000;
            $itemSubtotal = $unitPrice * $item['quantity'];
            $subtotal += $itemSubtotal;

            $items[] = [
                'cart_id' => $item['cart_id'],
                'product_id' => $item['product_id'],
                'product_option_id' => $item['product_option_id'],
                'quantity' => $item['quantity'],
            ];

            $calcItems[] = [
                'product_id' => $item['product_id'],
                'product_option_id' => $item['product_option_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $unitPrice,
                'subtotal' => $itemSubtotal,
                'final_amount' => $itemSubtotal,
            ];
        }

        return TempOrder::create([
            'user_id' => $userId,
            'cart_key' => $this->cartKey,
            'items' => $items,
            'calculation_result' => [
                'items' => $calcItems,
                'summary' => [
                    'subtotal' => $subtotal,
                    'total_discount' => 0,
                    'total_shipping' => 0,
                    'payment_amount' => $subtotal,
                    'final_amount' => $subtotal,
                ],
            ],
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    /**
     * 주문에 실린 만큼 장바구니를 다 쓴 줄은 주문 생성과 함께 사라진다.
     *
     * 남겨 두면 이미 산 것이 장바구니에 계속 떠 있어 구매자가 두 번 담은 것으로 읽는다.
     *
     * @scenario area=cart_cleanup
     *
     * @effects order_create_removes_fully_ordered_cart_row
     *
     * @return void 반환값 없음
     */
    public function test_주문_생성_후_장바구니_아이템_삭제_order_placed(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        // 장바구니에 상품 추가
        $cart = Cart::create([
            'user_id' => $user->id,
            'product_id' => $this->product->id,
            'product_option_id' => $this->productOption->id,
            'quantity' => 2,
        ]);

        // cart_id 포함 TempOrder 생성
        $this->createTempOrderWithCartIds($user->id, [
            ['cart_id' => $cart->id, 'product_id' => $this->product->id, 'product_option_id' => $this->productOption->id, 'quantity' => 2],
        ]);

        // dbank = order_placed 타이밍 (기본 설정)
        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload(),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);
        $this->assertDatabaseMissing('ecommerce_carts', ['id' => $cart->id]);
    }

    /**
     * 장바구니에 담아 둔 수량 중 일부만 주문하면 그 줄은 잔여 수량으로 줄어든다(삭제하지 않는다).
     *
     * @scenario area=cart_cleanup
     *
     * @effects order_create_decrements_partially_ordered_cart_row
     *
     * @return void 반환값 없음
     */
    public function test_주문_생성_후_장바구니_수량_차감(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        // 장바구니에 수량 5로 저장
        $cart = Cart::create([
            'user_id' => $user->id,
            'product_id' => $this->product->id,
            'product_option_id' => $this->productOption->id,
            'quantity' => 5,
        ]);

        // 주문 수량 2 → 잔여 3
        $this->createTempOrderWithCartIds($user->id, [
            ['cart_id' => $cart->id, 'product_id' => $this->product->id, 'product_option_id' => $this->productOption->id, 'quantity' => 2],
        ]);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload(),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);
        $this->assertDatabaseHas('ecommerce_carts', ['id' => $cart->id, 'quantity' => 3]);
    }

    /**
     * 한 주문에 「다 쓴 줄」과 「일부만 쓴 줄」이 섞여 있어도 각각 삭제·차감으로 갈린다.
     *
     * 한 규칙으로 뭉뚱그리면 다른 쪽이 조용히 틀린다 — 두 줄을 같은 주문에서 함께 잰다.
     *
     * @scenario area=cart_cleanup
     *
     * @effects order_create_handles_mixed_cart_rows
     *
     * @return void 반환값 없음
     */
    public function test_주문_생성_후_복수_장바구니_혼합_처리(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        // 두 번째 상품/옵션 생성
        $product2 = Product::create([
            'name' => ['ko' => '테스트 상품2', 'en' => 'Test Product2'],
            'product_code' => 'TEST-'.Str::random(8),
            'sku' => 'SKU-'.Str::random(8),
            'list_price' => 20000,
            'selling_price' => 15000,
            'currency_code' => 'KRW',
            'stock_quantity' => 100,
            'sales_status' => ProductSalesStatus::ON_SALE,
            'display_status' => ProductDisplayStatus::VISIBLE,
            'has_options' => true,
        ]);
        $option2 = ProductOption::create([
            'product_id' => $product2->id,
            'option_code' => 'OPT-'.Str::random(8),
            'option_values' => ['색상' => '흰색'],
            'sku' => 'SKU-'.Str::random(8),
            'price_adjustment' => 0,
            'stock_quantity' => 50,
            'safe_stock_quantity' => 5,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // cart1: 수량 10, 주문 3 → 잔여 7
        $cart1 = Cart::create([
            'user_id' => $user->id,
            'product_id' => $this->product->id,
            'product_option_id' => $this->productOption->id,
            'quantity' => 10,
        ]);

        // cart2: 수량 2, 주문 2 → 삭제
        $cart2 = Cart::create([
            'user_id' => $user->id,
            'product_id' => $product2->id,
            'product_option_id' => $option2->id,
            'quantity' => 2,
        ]);

        $this->createTempOrderWithCartIds($user->id, [
            ['cart_id' => $cart1->id, 'product_id' => $this->product->id, 'product_option_id' => $this->productOption->id, 'quantity' => 3],
            ['cart_id' => $cart2->id, 'product_id' => $product2->id, 'product_option_id' => $option2->id, 'quantity' => 2],
        ]);

        $totalAmount = 15000 * 3 + 15000 * 2; // 75000
        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->buildOrderPayload(['expected_total_amount' => $totalAmount]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(201);
        $this->assertDatabaseHas('ecommerce_carts', ['id' => $cart1->id, 'quantity' => 7]);
        $this->assertDatabaseMissing('ecommerce_carts', ['id' => $cart2->id]);
    }

    /**
     * 마이페이지 주문내역이 그 주문에 접수된 클레임 요약을 함께 내려준다.
     *
     * 주문 목록 조회는 관리자·구매자가 같은 저장소 메서드를 쓴다. 그 메서드가 클레임 관계와
     * 집계를 항상 싣게 된 뒤로 이 값은 이미 손에 있었는데 구매자 응답만 직렬화를 하지 않아,
     * 불러온 것을 그대로 버리면서 구매자는 자기 주문의 교환·반품 진행 상태를 목록에서 볼 수
     * 없었다. 오류도 로그도 남지 않는 형태였다.
     *
     * @scenario claim_presence=with_claim
     *
     * @effects mypage_order_list_serializes_claim_summary
     *
     * @return void 반환값 없음
     */
    public function test_마이페이지_주문내역이_클레임_요약을_내려준다(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::DELIVERED,
        ]);

        OrderExchange::factory()->create(['order_id' => $order->id]);

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $response->assertOk();

        $row = collect($response->json('data.data'))->firstWhere('id', $order->id);

        $this->assertNotNull($row, '방금 만든 주문이 목록에 없다');
        $this->assertArrayHasKey('claim_summary', $row, '구매자 응답에 클레임 요약이 없다');

        $exchange = collect($row['claim_summary'])->firstWhere('type', 'exchange');

        $this->assertNotNull($exchange, '접수된 교환이 요약에 실리지 않았다');
        $this->assertSame(1, $exchange['count']);
        $this->assertNotEmpty($exchange['status_label'], '배지에 그릴 상태 라벨이 비어 있다');
        // 마이페이지 배지는 이 값으로 classMap 을 고른다 — 비면 7어휘가 전부 회색 하나가 된다.
        // 관리자 쪽과 같은 트레이트가 만들지만, 한쪽만 단언하면 그 공유가 깨져도 드러나지 않는다.
        $this->assertNotEmpty($exchange['status_variant'], '요약이 배지 variant 를 싣지 않았다 — 마이페이지 배지가 무색이 된다');
        $this->assertNotEmpty($exchange['type_label'], '유형 라벨이 비어 있다 — 상태 라벨이 없을 때의 폴백 표기가 사라진다');
    }

    /**
     * 클레임이 없는 주문은 빈 배열로 내려간다 (키 자체가 빠지지 않는다).
     *
     * 키가 없으면 화면이 "아직 안 왔다" 와 "없다" 를 구분하지 못해 `?? []` 분기가 번진다.
     *
     * @scenario claim_presence=without_claim
     *
     * @effects mypage_order_without_claim_serializes_empty_array
     *
     * @return void 반환값 없음
     */
    public function test_클레임이_없는_주문은_빈_배열을_내려준다(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::DELIVERED,
        ]);

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $row = collect($response->assertOk()->json('data.data'))->firstWhere('id', $order->id);

        // 형제 케이스와 같은 강도로 먼저 확인한다 — 이 단언이 없으면 목록 조회가 깨졌을 때
        // 원인이 "요약 직렬화" 가 아니라 "목록에 주문이 없다" 인데도 TypeError 로 죽어
        // 리포트가 두 원인을 구분하지 못한다.
        $this->assertNotNull($row, '방금 만든 주문이 목록에 없다');
        $this->assertArrayHasKey('claim_summary', $row, '클레임이 없어도 요약 키는 있어야 한다');
        $this->assertSame([], $row['claim_summary']);

        // 위 단언만으로는 이 케이스의 이름(`..._serializes_empty_array`)이 약속한 것을 고정하지
        // 못한다 — `$response->json()` 은 연관배열로 디코드하므로 JSON 의 `{}` 와 `[]` 가 둘 다
        // PHP `[]` 가 되어 구분되지 않는다. 화면이 이 값을 `iteration` 으로 도는데 `{}` 가 오면
        // 반복이 돌지 않고, 그 사실은 오류 없이 "배지가 안 보인다" 로만 나타난다.
        // 형제 `OrderListDeferredJoinTest` 가 같은 함정을 객체 디코드로 가른다.
        $decoded = json_decode($response->getContent(), false);
        $rawRow = collect($decoded->data->data)->firstWhere('id', $order->id);

        $this->assertNotNull($rawRow, '객체 디코드에서 주문을 찾지 못했다');
        $this->assertIsArray(
            $rawRow->claim_summary,
            'claim_summary 가 빈 객체(`{}`)로 직렬화됐다 — 화면 반복이 돌지 않는다'
        );
    }

    /**
     * 목록 상단 건수 칸 배열 — 통계와 같은 키·건수에 아이콘 토큰·색·라벨이 붙는다.
     * 합산 카운터(상품준비중)는 그룹 라벨을 쓴다.
     *
     * @scenario area=list
     *
     * @effects mypage_order_list_status_summary_matches_statistics
     *
     * @return void 반환값 없음
     */
    public function test_주문_목록_상단_건수_칸_배열(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        Order::factory()->count(2)->forUser($user)->pendingPayment()->create();
        Order::factory()->count(3)->forUser($user)->shipping()->create();

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/orders');

        $response->assertStatus(200)
            ->assertJsonCount(6, 'data.status_summary')
            ->assertJsonPath('data.status_summary.0.key', 'pending_payment')
            ->assertJsonPath('data.status_summary.0.count', 2)
            ->assertJsonPath('data.status_summary.0.icon', 'status-payment-pending')
            ->assertJsonPath('data.status_summary.0.variant', 'warning')
            ->assertJsonPath('data.status_summary.0.label', __('sirsoft-ecommerce::enums.order_status.pending_payment'))
            ->assertJsonPath('data.status_summary.2.key', 'preparing')
            ->assertJsonPath('data.status_summary.2.label', __('sirsoft-ecommerce::enums.order_status_groups.preparing'))
            ->assertJsonPath('data.status_summary.3.key', 'shipping')
            ->assertJsonPath('data.status_summary.3.count', 3);

        // 칸 순서는 통계 키 순서와 같다 — 클릭 필터가 같은 키를 보낸다.
        $this->assertSame(
            array_keys($response->json('data.statistics')),
            array_column($response->json('data.status_summary'), 'key'),
        );

        // 목록 행에도 상태 아이콘 토큰이 실린다.
        $this->assertSame('status-dispatch', collect($response->json('data.data'))->firstWhere('status', 'shipping')['status_icon']);
    }

    /**
     * 주문 상세에 진행단계 배열과 상태 아이콘 토큰이 실린다 — 관리자 상세와 같은 배열.
     *
     * @scenario area=show
     *
     * @effects mypage_order_show_carries_progress_steps
     *
     * @return void 반환값 없음
     */
    public function test_본인_주문_상세에_진행단계가_실린다(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $order = Order::factory()->forUser($user)->shipping()->create();

        $response = $this->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.order_status_icon', 'status-dispatch')
            ->assertJsonCount(7, 'data.progress_steps')
            ->assertJsonPath('data.progress_steps.4.value', 'shipping')
            ->assertJsonPath('data.progress_steps.4.state', 'current')
            ->assertJsonPath('data.progress_steps.4.icon', 'status-dispatch')
            ->assertJsonPath('data.progress_steps.6.state', 'upcoming');
    }
}
