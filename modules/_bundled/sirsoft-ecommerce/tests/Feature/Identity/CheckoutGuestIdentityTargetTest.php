<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Identity;

use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Enums\IdentityVerificationStatus;
use App\Exceptions\IdentityVerificationRequiredException;
use App\Extension\Helpers\IdentityPolicySyncHelper;
use App\Extension\HookManager;
use App\Listeners\Identity\EnforceIdentityPolicyListener;
use App\Models\IdentityVerificationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 비회원 결제 본인인증 결과가 주문자 이메일에 묶이는지 검증한다 (코어 7.1.6 KVE 동형 B4).
 *
 * 수정 전: 결제 직전 훅이 대상 이메일을 넘기지 않아, 비회원은 남의(또는 아무) 인증 결과로 결제 정책을 통과했다.
 */
class CheckoutGuestIdentityTargetTest extends ModuleTestCase
{
    private const POLICY = 'sirsoft-ecommerce.checkout.before_pay';

    /**
     * 모듈 선언 정책을 동기화합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $helper = app(IdentityPolicySyncHelper::class);
        $module = new Module('sirsoft-ecommerce', $this->getModuleBasePath());
        foreach ($module->getIdentityPolicies() as $policy) {
            $helper->syncPolicy(array_merge($policy, ['source_type' => 'module', 'source_identifier' => 'sirsoft-ecommerce']));
        }
    }

    /**
     * 결제 본인인증 결과를 만듭니다.
     *
     * @param  string  $email  인증한 이메일
     * @return IdentityVerificationLog 결과
     */
    private function verifiedFor(string $email): IdentityVerificationLog
    {
        return IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'checkout_verification',
            'channel' => 'email',
            'target_hash' => hash('sha256', mb_strtolower($email)),
            'status' => IdentityVerificationStatus::Verified->value,
            'verification_token' => 'tok-'.Str::random(24),
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    /**
     * 주문 생성 진입이 결제 직전 훅에 주문자 이메일을 대상으로 넘긴다.
     *
     * @scenario actor=guest, entry=http_order_create
     *
     * @effects checkout_hook_passes_orderer_email
     *
     * @return void 반환값 없음
     */
    public function test_checkout_hook_receives_orderer_email(): void
    {
        $cartKey = $this->prepareTempOrder();
        $captured = null;
        $callback = function (...$args) use (&$captured) {
            $captured = $args[2] ?? null;
        };
        HookManager::addAction('sirsoft-ecommerce.checkout.before_payment', $callback, 10);

        try {
            $this->postJson('/api/modules/sirsoft-ecommerce/user/orders', $this->orderBody('guest-target@test.com'), ['X-Cart-Key' => $cartKey]);
        } finally {
            HookManager::removeAction('sirsoft-ecommerce.checkout.before_payment', $callback);
        }

        $this->assertSame(['target_email' => 'guest-target@test.com'], $captured);
    }

    /**
     * 비회원 결제 정책은 다른 이메일로 받은 결과를 거부하고(428 이 주문자 이메일을 되돌림), 주문자 이메일의 결과는 받는다.
     *
     * @scenario actor=guest, entry=before_payment_hook
     *
     * @effects guest_checkout_token_bound_to_orderer, guest_checkout_orderer_token_accepted_and_consumed
     *
     * @return void 반환값 없음
     */
    public function test_guest_checkout_token_must_match_orderer_email(): void
    {
        app(IdentityPolicyRepositoryInterface::class)->updateByKey(self::POLICY, ['enabled' => true, 'grace_minutes' => 0], ['enabled', 'grace_minutes']);
        EnforceIdentityPolicyListener::syncDynamicHookSubscriptions();

        $other = $this->verifiedFor('someone@test.com');
        $this->app->instance('request', Request::create('/api/modules/sirsoft-ecommerce/user/orders', 'POST', ['verification_token' => $other->verification_token]));

        try {
            HookManager::doAction('sirsoft-ecommerce.checkout.before_payment', null, null, ['target_email' => 'buyer@test.com']);
            $this->fail('다른 이메일의 인증 결과로 비회원 결제 정책이 통과했습니다.');
        } catch (IdentityVerificationRequiredException $e) {
            $this->assertSame('buyer@test.com', $e->getPayload()['target']['email'] ?? null);
        }

        $own = $this->verifiedFor('buyer@test.com');
        $this->app->instance('request', Request::create('/api/modules/sirsoft-ecommerce/user/orders', 'POST', ['verification_token' => $own->verification_token]));
        HookManager::doAction('sirsoft-ecommerce.checkout.before_payment', null, null, ['target_email' => 'buyer@test.com']);

        $this->assertNotNull($own->fresh()->consumed_at);
        EnforceIdentityPolicyListener::resetDynamicSubscriptions();
    }

    /**
     * 상품·옵션·임시주문을 만들고 장바구니 키를 돌려줍니다.
     *
     * @return string 장바구니 키
     */
    private function prepareTempOrder(): string
    {
        $product = Product::create([
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
        $option = ProductOption::create([
            'product_id' => $product->id,
            'option_code' => 'OPT-'.Str::random(8),
            'option_values' => ['색상' => '검정'],
            'sku' => 'SKU-'.Str::random(8),
            'price_adjustment' => 0,
            'stock_quantity' => 50,
            'safe_stock_quantity' => 5,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $cartKey = Str::uuid()->toString();
        TempOrder::create([
            'user_id' => null,
            'cart_key' => $cartKey,
            'items' => [['product_id' => $product->id, 'product_option_id' => $option->id, 'quantity' => 1]],
            'calculation_result' => [
                'items' => [[
                    'product_id' => $product->id,
                    'product_option_id' => $option->id,
                    'quantity' => 1,
                    'unit_price' => 15000,
                    'subtotal' => 15000,
                    'final_amount' => 15000,
                ]],
                'summary' => ['subtotal' => 15000, 'total_discount' => 0, 'total_shipping' => 0, 'payment_amount' => 15000, 'final_amount' => 15000],
            ],
            'expires_at' => now()->addMinutes(30),
        ]);

        return $cartKey;
    }

    /**
     * 비회원 주문 본문을 만듭니다.
     *
     * @param  string  $email  주문자 이메일
     * @return array<string, mixed> 요청 본문
     */
    private function orderBody(string $email): array
    {
        return [
            'orderer' => ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => $email],
            'shipping' => [
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9876-5432',
                'country_code' => 'KR',
                'zipcode' => '12345',
                'address' => '서울시 강남구 테헤란로 123',
                'address_detail' => '101동 1001호',
            ],
            'payment_method' => PaymentMethodEnum::DBANK->value,
            'expected_total_amount' => 15000,
            'depositor_name' => '홍길동',
            'dbank' => ['bank_code' => 'KB', 'bank_name' => '국민은행', 'account_number' => '123-456-789012', 'account_holder' => '주식회사 테스트'],
            'guest_lookup_password' => 'guest1234',
            'guest_lookup_password_confirmation' => 'guest1234',
        ];
    }
}
