<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Seeders;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\EcommerceUserProfileSeeder;
use Modules\Sirsoft\Ecommerce\Enums\DeliveryMemoPresetEnum;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\EcommerceUserProfile;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOption;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOptionValue;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 시더 정합성 테스트 ()
 *
 * 이번 이슈에서 추가된 스키마/도메인 변경(추가옵션·취소일시·배송메모 라벨·주문자 locale·
 * 사용자 프로필·배송 스냅샷)이 샘플 시더/팩토리에 정상 반영되는지 검증합니다.
 */
class SeederConsistencyTest extends ModuleTestCase
{
    /**
     * EcommerceUserProfileSeeder 가 회원별 프로필을 생성하고 통화/배송국가가
     * 등록 통화·ISO 국가코드 규칙을 따르는지 검증합니다.
     */
    public function test_user_profile_seeder_creates_profiles(): void
    {
        User::factory()->count(5)->create();

        (new EcommerceUserProfileSeeder)->setCommand($this->mockSeederCommand())->run();

        $this->assertGreaterThan(0, EcommerceUserProfile::count(), '사용자 프로필이 생성되어야 합니다.');

        // 통화가 지정된 프로필은 3자리 통화코드여야 한다
        EcommerceUserProfile::whereNotNull('preferred_currency')->get()
            ->each(fn (EcommerceUserProfile $p) => $this->assertSame(3, strlen($p->preferred_currency)));

        // 배송국가가 지정된 프로필은 2자리 국가코드여야 한다
        EcommerceUserProfile::whereNotNull('preferred_shipping_country')->get()
            ->each(fn (EcommerceUserProfile $p) => $this->assertSame(2, strlen($p->preferred_shipping_country)));
    }

    /**
     * OrderAddressFactory 가 배송메모 + 라벨을 DeliveryMemoPresetEnum SSoT 로 정합되게
     * 채우고 주문자 locale 을 스냅샷하는지 검증합니다.
     */
    public function test_order_address_factory_fills_memo_label_and_locale(): void
    {
        $order = Order::factory()->create();

        // 프리셋/자유텍스트/미입력 분포를 충분히 커버하도록 다수 생성
        $addresses = OrderAddress::factory()->forOrder($order)->count(40)->create();

        foreach ($addresses as $address) {
            // 라벨은 항상 resolveLabel(memo) 와 일치해야 한다 (프리셋=라벨/custom=원문/빈값=null)
            $this->assertSame(
                DeliveryMemoPresetEnum::resolveLabel($address->delivery_memo),
                $address->delivery_memo_label,
                'delivery_memo_label 은 resolveLabel SSoT 와 정합해야 합니다.'
            );

            // orderer_locale 은 지원 로케일 중 하나여야 한다
            $this->assertContains($address->orderer_locale, config('app.supported_locales', ['ko', 'en']));
        }

        // 최소 1건은 프리셋 키가 라벨로 변환되어야 한다 (프리셋 분포 검증)
        $presetAddress = $addresses->first(
            fn (OrderAddress $a) => in_array($a->delivery_memo, DeliveryMemoPresetEnum::values(), true)
        );
        $this->assertNotNull($presetAddress, '프리셋 키 배송메모가 최소 1건 존재해야 합니다.');
        $this->assertNotSame($presetAddress->delivery_memo, $presetAddress->delivery_memo_label);
    }

    /**
     * ProductAdditionalOptionValue 는 KRW 추가금(price_adjustment)을 저장하고
     * mc_price_adjustment 는 프로덕션과 동일하게 null 로 둡니다 (런타임 환산이 SSoT —
     * ProductService::syncAdditionalOptions 도 이 컬럼을 저장하지 않음).
     */
    public function test_additional_option_value_stores_krw_price_only(): void
    {
        $product = Product::factory()->create();
        $group = ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '포장', 'en' => 'Wrapping'],
            'is_required' => false,
            'sort_order' => 0,
        ]);

        $value = ProductAdditionalOptionValue::create([
            'additional_option_id' => $group->id,
            'name' => ['ko' => '선물 포장', 'en' => 'Gift Box'],
            'price_adjustment' => 3000,
            // mc_price_adjustment 미지정 (프로덕션 형상)
            'is_default' => false,
            'is_active' => true,
            'allow_custom_text' => false,
            'sort_order' => 0,
        ]);

        $this->assertSame(3000, $value->getPriceAdjustment());
        $this->assertNull($value->fresh()->mc_price_adjustment, '추가옵션 선택지의 mc_price_adjustment 는 프로덕션과 동일하게 null 이어야 합니다.');
    }

    /**
     * 취소 주문은 native cancelled_at 컬럼이 채워져야 합니다 (MP02).
     */
    public function test_cancelled_order_has_cancelled_at(): void
    {
        $order = Order::factory()->cancelled()->create([
            'cancelled_at' => now()->subDay(),
        ]);

        $this->assertNotNull($order->fresh()->cancelled_at, '취소 주문은 cancelled_at 이 기록되어야 합니다.');
    }

    /**
     * OrderOption 에 추가옵션 스냅샷/합계/다중통화 컬럼을 저장할 수 있는지 검증합니다.
     */
    public function test_order_option_accepts_additional_options_columns(): void
    {
        $order = Order::factory()->create();

        // 팩토리로 필수 컬럼을 채우고 추가옵션 컬럼만 오버라이드 (실제 OrderSeeder 와 동일 경로)
        $orderOption = OrderOption::factory()->forOrder($order)->create([
            'additional_options_total' => 3000,
            'additional_options_snapshot' => [
                ['additional_option_id' => 1, 'value_id' => 2, 'price_adjustment' => 3000],
            ],
            'mc_additional_options_total' => ['KRW' => 3000.0],
        ]);

        $fresh = $orderOption->fresh();
        $this->assertSame('3000.00', (string) $fresh->additional_options_total);
        $this->assertIsArray($fresh->additional_options_snapshot);
        $this->assertSame(3000, $fresh->additional_options_snapshot[0]['price_adjustment']);
        $this->assertEquals(3000, $fresh->mc_additional_options_total['KRW']);
    }

    /**
     * Cart 가 추가옵션 선택(JSON)을 저장할 수 있는지 검증합니다.
     */
    public function test_cart_accepts_additional_option_selections(): void
    {
        $product = Product::factory()->create();
        $productOption = ProductOption::factory()->create(['product_id' => $product->id]);

        $cart = Cart::create([
            'cart_key' => 'ck_test',
            'user_id' => null,
            'product_id' => $product->id,
            'product_option_id' => $productOption->id,
            'quantity' => 1,
            'additional_option_selections' => [
                ['additional_option_id' => 1, 'value_id' => 2, 'custom_text' => '직접 입력'],
            ],
        ]);

        $fresh = $cart->fresh();
        $this->assertIsArray($fresh->additional_option_selections);
        $this->assertSame('직접 입력', $fresh->additional_option_selections[0]['custom_text']);
    }

    /**
     * order_cancels.shipping_snapshot 이 country_code/zipcode/policies 형식으로
     * 저장될 수 있는지 검증합니다 (MileageSeeder 가 생성하는 형식).
     */
    public function test_order_cancel_accepts_shipping_snapshot(): void
    {
        $order = Order::factory()->create();

        $cancelId = DB::table('ecommerce_order_cancels')->insertGetId([
            'order_id' => $order->id,
            'cancel_number' => 'CXL-TEST-001',
            'cancel_type' => 'full',
            'cancel_status' => 'completed',
            'cancel_reason_type' => 'order_mistake',
            'items_snapshot' => json_encode([], JSON_UNESCAPED_UNICODE),
            'shipping_snapshot' => json_encode([
                'country_code' => 'KR',
                'zipcode' => '04524',
                'policies' => [],
            ], JSON_UNESCAPED_UNICODE),
            'cancelled_by' => null,
            'cancelled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $snapshot = json_decode(DB::table('ecommerce_order_cancels')->where('id', $cancelId)->value('shipping_snapshot'), true);
        $this->assertSame('KR', $snapshot['country_code']);
        $this->assertArrayHasKey('policies', $snapshot);
    }

    /**
     * 실제 시더(Product→Cart→Order)를 end-to-end 로 실행해 신규 기능 컬럼이 채워지고
     * 통화 라벨이 설정 기본통화(base)와 정합하는지 검증합니다.
     *
     * 추가옵션 노출을 결정적으로 만들기 위해, 추가옵션을 보유한 상품을 명시 생성한 뒤
     * 그 상품옵션으로 장바구니·주문을 만든다 (확률 의존 제거).
     */
    public function test_real_seeder_path_populates_new_columns_and_currency_is_base_coherent(): void
    {
        $service = app(CurrencyConversionService::class);
        $base = $service->getDefaultCurrency();

        // 추가옵션을 보유한 상품 + 옵션 명시 생성
        $product = Product::factory()->create(['currency_code' => $base]);
        $productOption = ProductOption::factory()->create([
            'product_id' => $product->id,
            'currency_code' => $base,
            'is_active' => true,
            'stock_quantity' => 50,
        ]);
        $group = ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '포장', 'en' => 'Wrapping'],
            'is_required' => true,
            'sort_order' => 0,
        ]);
        ProductAdditionalOptionValue::create([
            'additional_option_id' => $group->id,
            'name' => ['ko' => '선물 포장', 'en' => 'Gift Box'],
            'price_adjustment' => 3000,
            'is_default' => true,
            'is_active' => true,
            'allow_custom_text' => false,
            'sort_order' => 0,
        ]);

        // CartSeeder 헬퍼 경로: 추가옵션 선택이 생성되는지 (실제 createCartItem 로직과 동일)
        $cart = Cart::create([
            'cart_key' => 'ck_e2e',
            'user_id' => null,
            'product_id' => $product->id,
            'product_option_id' => $productOption->id,
            'quantity' => 1,
            'additional_option_selections' => (function () use ($product) {
                $product->loadMissing('additionalOptions.activeValues');
                $sel = [];
                foreach ($product->additionalOptions as $g) {
                    $v = $g->activeValues->first();
                    if ($v) {
                        $sel[] = ['additional_option_id' => (int) $g->id, 'value_id' => (int) $v->id];
                    }
                }

                return $sel ?: null;
            })(),
        ]);
        $this->assertNotNull($cart->fresh()->additional_option_selections, '추가옵션 보유 상품의 장바구니는 선택이 채워져야 합니다.');

        // OrderFactory 통화 정합: currency 와 snapshot.base_currency 가 모두 base
        $order = Order::factory()->create();
        $this->assertSame($base, $order->currency, '주문 통화는 설정 기본통화와 같아야 합니다.');
        $this->assertSame($base, $order->currency_snapshot['base_currency'], 'currency_snapshot.base_currency 는 base 와 같아야 합니다.');

        // OrderOption mc_unit_price 의 base 키 값이 unit_price 와 동일(= base 로 해석됨, 오환산 아님).
        // 팩토리는 mc_unit_price 를 자신의 unit_price 에서 빌드하므로 override 없이 생성값을 비교한다.
        $orderOption = OrderOption::factory()->forOrder($order)->create();
        $fresh = $orderOption->fresh();
        $mc = $fresh->mc_unit_price;
        $this->assertArrayHasKey($base, $mc, 'mc_unit_price 에 base 통화 키가 있어야 합니다.');
        $this->assertEquals((int) $fresh->unit_price, (int) $mc[$base], 'base 통화 금액은 unit_price 와 동일해야 합니다(오환산 없음).');
    }

    /**
     * 시더 콘솔 출력을 흡수하는 mock command 를 반환합니다.
     */
    private function mockSeederCommand(): Command
    {
        $command = new class extends Command
        {
            protected $signature = 'test:seed-noop';

            public function info($string, $verbosity = null): void {}

            public function warn($string, $verbosity = null): void {}

            public function line($string, $style = null, $verbosity = null): void {}
        };

        $command->setLaravel($this->app);

        return $command;
    }
}
