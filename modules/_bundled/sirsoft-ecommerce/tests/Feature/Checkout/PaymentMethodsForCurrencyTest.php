<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Checkout;

use App\Extension\HookManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\CreateOrderRequest;
use Modules\Sirsoft\Ecommerce\Services\CheckoutDataService;
use Modules\Sirsoft\Ecommerce\Services\CheckoutPaymentMethodFilter;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 결제 통화별 결제수단 제한 (#639 ⑤, 결정 D17)
 *
 * 엔화 주문에서 쓸 수 없는 결제수단을 숨기는 일을 결제 플러그인이 브라우저에서 했다 — 주문서
 * 응답을 `window.fetch` 덮어쓰기로 고쳐 버튼만 지웠다. 서버는 그 판정을 몰라 직접 POST 하면
 * 그대로 주문이 만들어졌다. 판정을 쇼핑몰 필터 훅
 * `sirsoft-ecommerce.checkout.filter_payment_methods_for_currency` 로 옮기고, 주문 생성 검증과
 * 주문서 표시가 같은 판정을 쓰게 한다.
 */
class PaymentMethodsForCurrencyTest extends ModuleTestCase
{
    private const HOOK = 'sirsoft-ecommerce.checkout.filter_payment_methods_for_currency';

    private string $storagePath;

    /**
     * 결제수단 설정 파일과 엔화 제한 훅을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }

        File::ensureDirectoryExists($this->storagePath);
        File::put($this->storagePath.'/order_settings.json', json_encode([
            'default_pg_provider' => null,
            'payment_methods' => [
                ['id' => 'card', 'is_active' => true],
                ['id' => 'dbank', 'is_active' => true],
            ],
        ], JSON_PRETTY_PRINT));

        app(EcommerceSettingsService::class)->clearCache();
        app(PaymentMethodResolver::class)->flushCache();

        // 엔화에서는 무통장입금만 허용하는 확장을 흉내 낸다.
        HookManager::clearFilter(self::HOOK);
        HookManager::addFilter(self::HOOK, function (array $methodIds, string $currency): array {
            return $currency === 'JPY' ? array_values(array_diff($methodIds, ['card'])) : $methodIds;
        });
    }

    /**
     * 훅과 설정 파일을 걷어냅니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(self::HOOK);
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }

        parent::tearDown();
    }

    /**
     * 결제 통화 헤더를 실은 요청 문맥에서 payment_method 규칙만 검증합니다.
     *
     * @param  string  $methodId  결제수단 ID
     * @param  string  $currency  결제 통화 (X-Currency)
     * @return \Illuminate\Contracts\Validation\Validator 검증기
     */
    private function validateFor(string $methodId, string $currency)
    {
        request()->headers->set('X-Currency', $currency);
        $rules = (new CreateOrderRequest)->rules();

        return Validator::make(['payment_method' => $methodId], ['payment_method' => $rules['payment_method']]);
    }

    /**
     * 엔화 주문에 허용 외 수단을 직접 제출하면 거부된다. (선-실패)
     *
     * @scenario currency=JPY, method=disallowed
     *
     * @effects disallowed_method_for_currency_rejected_422
     *
     * @return void 반환값 없음
     */
    public function test_jpy_order_with_disallowed_method_is_rejected(): void
    {
        $validator = $this->validateFor('card', 'JPY');

        $this->assertTrue($validator->fails(), '엔화 주문에 허용되지 않은 결제수단이 검증을 통과했습니다.');
        $this->assertNotSame(
            'sirsoft-ecommerce::validation.order.payment_method_not_allowed_for_currency',
            $validator->errors()->first('payment_method'),
            '다국어 키가 원문 그대로 노출되었습니다.'
        );
    }

    /**
     * 같은 수단도 원화 주문에서는 통과한다. (비회귀)
     *
     * @scenario currency=KRW, method=allowed
     *
     * @effects allowed_method_for_currency_accepted
     *
     * @return void 반환값 없음
     */
    public function test_krw_order_with_same_method_passes(): void
    {
        $this->assertFalse($this->validateFor('card', 'KRW')->fails());
    }

    /**
     * 주문서 표시와 주문 생성 검증은 같은 판정을 쓴다.
     *
     * @scenario currency=JPY, method=disallowed
     *
     * @effects checkout_display_and_validation_share_currency_filter
     *
     * @return void 반환값 없음
     */
    public function test_display_filter_matches_validation(): void
    {
        $filter = app(CheckoutPaymentMethodFilter::class);

        $this->assertSame(['dbank'], $filter->allowedFor(['card', 'dbank'], 'JPY'));
        $this->assertSame(['card', 'dbank'], $filter->allowedFor(['card', 'dbank'], 'KRW'));
    }

    /**
     * 주문서 응답의 허용 수단 목록은 계산 결과의 결제 통화로, 없으면 요청 통화로 같은 판정을 쓴다.
     *
     * @scenario currency=JPY, method=disallowed
     *
     * @effects checkout_display_and_validation_share_currency_filter
     *
     * @return void 반환값 없음
     */
    public function test_checkout_response_lists_methods_allowed_for_payment_currency(): void
    {
        $service = app(CheckoutDataService::class);
        $build = fn (array $calculation): array => (fn () => $this->buildAllowedPaymentMethods($calculation))->call($service);

        $jpy = $build(['summary' => ['selected_payment_currency' => 'JPY']]);
        $this->assertNotContains('card', $jpy);
        $this->assertContains('dbank', $jpy);

        $this->assertContains('card', $build(['summary' => ['selected_payment_currency' => 'KRW']]));

        request()->headers->set('X-Currency', 'JPY');
        $this->assertNotContains('card', $build([]), '계산 결과에 통화가 없으면 주문 생성과 같은 규칙으로 구한 통화를 쓴다');
    }

    /**
     * 확장이 없는 목록을 돌려주거나 형식이 틀려도 원래 목록에 없는 수단은 끼어들지 못한다.
     *
     * @scenario currency=JPY, method=injected
     *
     * @effects filter_cannot_add_unknown_methods
     *
     * @return void 반환값 없음
     */
    public function test_filter_cannot_add_methods(): void
    {
        HookManager::addFilter(self::HOOK, fn (array $ids): array => array_merge($ids, ['ghost_pay']), 20);

        $this->assertSame(['dbank'], app(CheckoutPaymentMethodFilter::class)->allowedFor(['card', 'dbank'], 'JPY'));
    }
}
