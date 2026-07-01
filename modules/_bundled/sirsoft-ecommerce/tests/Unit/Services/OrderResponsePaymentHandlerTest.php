<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Traits\HandlesOrderCreation;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 생성 응답의 결제 핸들러명(pg_payment_handler) 매핑 테스트
 *
 * provider-agnostic 결제 진입 — PG provider 레지스트리(`registered_pg_providers` 필터 훅)가
 * 선언한 프론트 결제 진입 핸들러 풀네임(`payment_handler`)을 주문 응답이 `pg_payment_handler`
 * 로 내려주는지 검증. 미선언 provider 는 키 부재(템플릿 PG 분기 미발화).
 */
class OrderResponsePaymentHandlerTest extends ModuleTestCase
{
    protected function tearDown(): void
    {
        HookManager::resetAll();
        parent::tearDown();
    }

    /**
     * HandlesOrderCreation 의 protected resolvePgPaymentHandler 를 노출하는 stub.
     */
    private function traitHost(): object
    {
        return new class
        {
            use HandlesOrderCreation;

            public function callResolve(string $provider): ?string
            {
                return $this->resolvePgPaymentHandler($provider);
            }
        };
    }

    /**
     * provider 가 등록한 후보 엔트리를 registered_pg_providers 필터 훅에 주입.
     *
     * @param  array<int, array<string, mixed>>  $providers
     */
    private function registerProviders(array $providers): void
    {
        HookManager::addFilter(
            'sirsoft-ecommerce.payment.registered_pg_providers',
            fn (array $existing) => array_merge($existing, $providers)
        );
    }

    public function test_provider_declared_payment_handler_is_resolved(): void
    {
        $this->registerProviders([
            ['id' => 'kginicis', 'payment_handler' => 'sirsoft-pay_kginicis.requestPayment'],
        ]);

        $this->assertSame(
            'sirsoft-pay_kginicis.requestPayment',
            $this->traitHost()->callResolve('kginicis')
        );
    }

    public function test_provider_without_payment_handler_resolves_to_null(): void
    {
        $this->registerProviders([
            ['id' => 'someprovider', 'name' => 'Some PG'],
        ]);

        $this->assertNull($this->traitHost()->callResolve('someprovider'));
    }

    public function test_unknown_provider_resolves_to_null(): void
    {
        $this->registerProviders([
            ['id' => 'kginicis', 'payment_handler' => 'sirsoft-pay_kginicis.requestPayment'],
        ]);

        $this->assertNull($this->traitHost()->callResolve('not_registered'));
    }

    public function test_empty_payment_handler_string_resolves_to_null(): void
    {
        $this->registerProviders([
            ['id' => 'kginicis', 'payment_handler' => ''],
        ]);

        $this->assertNull($this->traitHost()->callResolve('kginicis'));
    }

    public function test_provider_handler_name_is_free_of_id_and_pg_provider_prefix(): void
    {
        // id='kginicis', 응답 pg_provider='sirsoft-kginicis', 핸들러='sirsoft-pay_kginicis.requestPayment'
        // 셋이 다른 문자열이어도 payment_handler 가 정확히 핸들러 등록명을 가리킨다(alias 불요).
        $this->registerProviders([
            ['id' => 'kginicis', 'payment_handler' => 'sirsoft-pay_kginicis.requestPayment'],
        ]);

        $resolved = $this->traitHost()->callResolve('kginicis');

        $this->assertSame('sirsoft-pay_kginicis.requestPayment', $resolved);
        $this->assertStringContainsString('sirsoft-pay_kginicis', $resolved);
    }

    public function test_multiple_providers_resolve_independently(): void
    {
        $this->registerProviders([
            ['id' => 'vendor_a', 'payment_handler' => 'vendor-a.pay'],
            ['id' => 'vendor_b', 'payment_handler' => 'vendor-b.checkout'],
            ['id' => 'vendor_c', 'name' => 'No handler'],
        ]);

        $host = $this->traitHost();
        $this->assertSame('vendor-a.pay', $host->callResolve('vendor_a'));
        $this->assertSame('vendor-b.checkout', $host->callResolve('vendor_b'));
        $this->assertNull($host->callResolve('vendor_c'));
    }
}
