<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Support\Facades\Config;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Modules\Sirsoft\Ecommerce\Support\CurrencySettingsCache;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * 주문 기준 통화 판정의 두 사본이 같은 답을 내는지 재는 검사.
 *
 * 신청 화면은 상품 줄을 `claimable-options` 응답의 onSuccess 에서 세우면서, 보조 통화 목록에서
 * **기준 통화를 걸러 낸다**. 그 기준을 `UserClaimService` 가 응답에 실어 보내고(`base_currency`),
 * 같은 화면의 다른 응답들은 리소스 계층 트레이트(`HasMultiCurrencyPrices`)가 정한 통화로 금액을
 * 표기한다. 두 판정이 갈리면 필터 기준과 표기 통화가 어긋나 **같은 금액이 두 줄로** 나온다 —
 * 양쪽 다 정상 서식이라 응답에도 로그에도 흔적이 남지 않는다.
 *
 * 두 사본은 규칙이 완전히 같지 않다. 트레이트는 선언된 기본 통화가 **통화 목록에 실재할 때만**
 * 채택하고 없으면 `is_default` 통화로 떨어지는데(삭제된 통화를 가리키는 설정 방어), 서비스 사본은
 * 선언값을 그대로 읽는다 — 이는 서비스 계층 형제들(`ProductService` · `ShippingPolicyService` ·
 * `SignupCurrencyResolver` · `CurrencyConversionService`)이 공유하는 관례다.
 *
 * 그래서 이 검사는 "두 사본이 언제 같고 언제 갈리는지" 를 통째로 고정한다. 어느 한쪽 규칙이
 * 바뀌면 그 즉시 red 가 되므로, 다음 작업이 이 어긋남을 모른 채 지나가지 않는다.
 */
class ClaimBaseCurrencyParityTest extends ModuleTestCase
{
    /**
     * 테스트 종료 시 통화 설정 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $path = storage_path('framework/testing/modules/sirsoft-ecommerce/settings/language_currency.json');

        if (file_exists($path)) {
            unlink($path);
        }

        CurrencySettingsCache::clear();

        parent::tearDown();
    }

    /**
     * 거래 시점 스냅샷이 있으면 두 사본이 같은 통화를 말한다.
     *
     * @return void 반환값 없음
     *
     * @effects snapshot_currency_is_reported_identically_by_both_copies
     */
    public function test_snapshot_currency_is_reported_identically_by_both_copies(): void
    {
        $this->writeCurrencySettings('KRW', ['KRW', 'USD']);
        $order = $this->orderWithSnapshot('USD');

        $this->assertSame('USD', $this->resourceRule($order));
        $this->assertSame('USD', $this->serviceRule($order));
    }

    /**
     * 스냅샷이 없어도, 선언된 기본 통화가 통화 목록에 실재하면 두 사본이 같은 통화를 말한다.
     *
     * @return void 반환값 없음
     *
     * @effects declared_default_is_reported_identically_when_it_exists_in_the_list
     */
    public function test_declared_default_is_reported_identically_when_it_exists_in_the_list(): void
    {
        $this->writeCurrencySettings('USD', ['KRW', 'USD']);
        $order = $this->orderWithSnapshot(null);

        $this->assertSame('USD', $this->resourceRule($order));
        $this->assertSame('USD', $this->serviceRule($order));
    }

    /**
     * 두 사본이 갈리는 **유일한 지점** — 스냅샷이 없고, 선언된 기본 통화가 목록에서 사라진 경우.
     *
     * 리소스 계층은 목록의 `is_default` 통화로 떨어지고, 서비스 사본은 사라진 선언값을 그대로 낸다.
     * 실데이터 노출은 현재 0 이다(주문 전건이 거래 시점 스냅샷을 보유). 서비스 사본을 리소스 규칙과
     * 맞추기로 결정하면 이 단언을 `assertSame` 으로 바꾼다 — 그때 형제 서비스 4곳과의 관례 차이를
     * 함께 판단해야 한다.
     *
     * @return void 반환값 없음
     *
     * @effects the_two_copies_diverge_only_when_the_declared_default_was_removed
     */
    public function test_the_two_copies_diverge_only_when_the_declared_default_was_removed(): void
    {
        $this->writeCurrencySettings('EUR', ['KRW', 'USD']);
        $order = $this->orderWithSnapshot(null);

        $this->assertSame('KRW', $this->resourceRule($order), '리소스 계층은 목록의 is_default 로 떨어진다');
        $this->assertSame('EUR', $this->serviceRule($order), '서비스 사본은 선언값을 그대로 낸다');
        $this->assertNotSame($this->resourceRule($order), $this->serviceRule($order));
    }

    /**
     * 리소스 계층(SSoT)의 판정 결과를 돌려줍니다.
     *
     * @param  Order  $order  주문
     * @return string 기준 통화 코드
     */
    private function resourceRule(Order $order): string
    {
        $probe = new class
        {
            use HasMultiCurrencyPrices;

            /**
             * 트레이트의 protected 판정을 그대로 노출합니다.
             *
             * @param  mixed  $order  주문
             * @return string 기준 통화 코드
             */
            public function resolve(mixed $order): string
            {
                return $this->resolveOrderBaseCurrencyCode($order);
            }
        };

        return $probe->resolve($order);
    }

    /**
     * 서비스 사본의 판정 결과를 돌려줍니다.
     *
     * 생성자 의존이 12개라 컨테이너 해석 대신 판정에 쓰이는 설정 서비스만 주입한다.
     *
     * @param  Order  $order  주문
     * @return string 기준 통화 코드
     */
    private function serviceRule(Order $order): string
    {
        $reflection = new ReflectionClass(UserClaimService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('settingsService')->setValue($service, app(EcommerceSettingsService::class));
        // 서비스 사본은 판정을 공용 헬퍼(`CurrencyConversionService::resolveOrderBaseCurrency`)에 위임한다(#599 P2 #14) —
        // 그 의존을 주입하지 않으면 판정 전에 초기화되지 않은 속성 오류로 끝난다.
        $reflection->getProperty('currencyService')->setValue($service, app(CurrencyConversionService::class));

        return (new ReflectionMethod(UserClaimService::class, 'orderBaseCurrency'))->invoke($service, $order);
    }

    /**
     * 테스트용 통화 설정을 기록하고 캐시를 비웁니다.
     *
     * @param  string  $declaredDefault  선언된 기본 통화 코드
     * @param  array<int, string>  $codes  통화 목록 (첫 항목이 `is_default`)
     * @return void 반환값 없음
     */
    private function writeCurrencySettings(string $declaredDefault, array $codes): void
    {
        $path = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');

        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $currencies = [];

        foreach ($codes as $index => $code) {
            $currencies[] = [
                'code' => $code,
                'name' => ['ko' => $code, 'en' => $code],
                'exchange_rate' => $code === 'KRW' ? null : 0.00075,
                'rounding_unit' => '1',
                'rounding_method' => 'floor',
                'is_default' => $index === 0,
            ];
        }

        $payload = [
            'default_language' => 'ko',
            'default_currency' => $declaredDefault,
            'currencies' => $currencies,
        ];

        file_put_contents($path.'/language_currency.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // 두 사본은 설정을 **다른 통로**로 읽는다 — 리소스 계층은 config 미러(`g7_module_settings`),
        // 서비스 사본은 `EcommerceSettingsService`. 한쪽만 세우면 이 검사가 두 규칙이 아니라 두
        // 통로의 차이를 재게 되므로, 같은 값을 양쪽 통로에 함께 놓는다.
        Config::set('g7_settings.modules.sirsoft-ecommerce.language_currency', $payload);

        CurrencySettingsCache::clear();
        app()->forgetInstance(EcommerceSettingsService::class);
    }

    /**
     * 거래 시점 통화 스냅샷을 가진(또는 갖지 않은) 주문을 만듭니다.
     *
     * @param  string|null  $baseCurrency  스냅샷의 기준 통화 (null 이면 스냅샷 없음)
     * @return Order 저장하지 않은 주문 모델
     */
    private function orderWithSnapshot(?string $baseCurrency): Order
    {
        $order = new Order;
        $order->currency_snapshot = $baseCurrency === null ? null : ['base_currency' => $baseCurrency];

        return $order;
    }
}
