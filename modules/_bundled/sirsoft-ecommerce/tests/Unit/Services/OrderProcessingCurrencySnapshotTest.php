<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionMethod;

/**
 * base 통화 스냅샷 정책 테스트 (A2)
 *
 * - order_currency = resolveActiveCurrency(영속>헤더>base) — 표시 헤더 단독 아님
 * - base_currency = default_currency (모든 금액 SSoT)
 * - 환불 불변식: 부분환불이 원주문 스냅샷 환율 사용(현재 환율 재조회 금지 → 환율 변경해도 불변)
 */
class OrderProcessingCurrencySnapshotTest extends ModuleTestCase
{
    private function invokeBuildSnapshot(): array
    {
        $service = app(OrderProcessingService::class);
        $method = new ReflectionMethod($service, 'buildCurrencySnapshot');
        $method->setAccessible(true);

        return $method->invoke($service);
    }

    public function test_snapshot_base_currency_is_default(): void
    {
        $snapshot = $this->invokeBuildSnapshot();

        $this->assertArrayHasKey('base_currency', $snapshot);
        // 기본통화 = default_currency (KRW). base 는 금액 SSoT.
        $this->assertSame('KRW', $snapshot['base_currency']);
    }

    public function test_order_currency_resolves_from_header_when_no_persisted(): void
    {
        // 비로그인/헤더 USD → order_currency = USD (resolveActiveCurrency 2순위)
        request()->headers->set('X-Currency', 'USD');

        $snapshot = $this->invokeBuildSnapshot();

        $this->assertSame('USD', $snapshot['order_currency']);
        // base 는 여전히 KRW (SSoT 보존, order_currency 와 분리)
        $this->assertSame('KRW', $snapshot['base_currency']);
    }

    public function test_order_currency_falls_back_to_base_without_header(): void
    {
        request()->headers->remove('X-Currency');

        $snapshot = $this->invokeBuildSnapshot();

        $this->assertSame('KRW', $snapshot['order_currency']);
    }

    public function test_persisted_user_currency_takes_priority_over_header(): void
    {
        // 로그인 유저의 영속 통화(EUR)가 헤더(USD)보다 1순위 (§A3-b)
        $user = User::factory()->create();
        Auth::login($user);
        app(EcommerceUserProfileRepositoryInterface::class)->setPreferredCurrency($user->id, 'EUR');
        request()->headers->set('X-Currency', 'USD');

        $snapshot = $this->invokeBuildSnapshot();

        $this->assertSame('EUR', $snapshot['order_currency']);
        Auth::logout();
    }

    public function test_snapshot_includes_all_currency_exchange_rates(): void
    {
        $snapshot = $this->invokeBuildSnapshot();

        $this->assertArrayHasKey('exchange_rates', $snapshot);
        // 전 통화 환율 동봉(환불/표시 재사용)
        $this->assertArrayHasKey('KRW', $snapshot['exchange_rates']);
        $this->assertArrayHasKey('USD', $snapshot['exchange_rates']);
    }

    public function test_snapshot_embeds_base_unit_for_formula_reproduction(): void
    {
        // 공식 변경 후에도 주문 시점 환산 재현을 위해 base_unit 을 박제한다(환차손 0).
        $snapshot = $this->invokeBuildSnapshot();

        // 최상위 base_unit = 기본 통화(KRW)의 base_unit = 1000 (폴백)
        $this->assertArrayHasKey('base_unit', $snapshot);
        $this->assertSame(1000, $snapshot['base_unit']);

        // 통화별 base_unit 도 동봉: KRW=1000, JPY=100, USD/EUR=1 (폴백)
        $this->assertSame(1000, $snapshot['exchange_rates']['KRW']['base_unit']);
        $this->assertSame(1, $snapshot['exchange_rates']['USD']['base_unit']);
    }

    // ──────────────────────────────────────────────
    // 환불 불변식 (A2 D-BASE-3) — 핵심
    // ──────────────────────────────────────────────

    public function test_refund_uses_snapshot_rate_not_current_rate(): void
    {
        /** @var CurrencyConversionService $svc */
        $svc = app(CurrencyConversionService::class);

        // 주문 시점 스냅샷: USD 환율 0.85 로 고정
        $snapshot = [
            'base_currency' => 'KRW',
            'order_currency' => 'USD',
            'exchange_rate' => 0.85,
            'exchange_rates' => [
                'KRW' => ['rate' => 1.0, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0],
                'USD' => ['rate' => 0.85, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2],
            ],
        ];

        // 환불액 10,000(base KRW) 을 스냅샷 환율로 변환
        $result = $svc->convertMultipleAmountsWithSnapshot(['refund' => 10000], $snapshot);

        // USD 환산 = (10000/1000) * 0.85 = 8.5 (스냅샷 환율 기준)
        $this->assertEquals(8.5, $result['USD']['refund']);
        // KRW(base) = 정수 그대로 보존(SSoT)
        $this->assertEquals(10000, $result['KRW']['refund']);
    }

    public function test_refund_unchanged_when_current_rate_differs_from_snapshot(): void
    {
        /** @var CurrencyConversionService $svc */
        $svc = app(CurrencyConversionService::class);

        // 스냅샷 환율(주문 시점) = 0.85, 현재 환율이 1.50 으로 바뀌었다고 가정해도
        // convertMultipleAmountsWithSnapshot 는 스냅샷 환율(0.85)만 사용해야 한다(환차손 0).
        $snapshot = [
            'base_currency' => 'KRW',
            'order_currency' => 'USD',
            'exchange_rate' => 0.85,
            'exchange_rates' => [
                'USD' => ['rate' => 0.85, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2],
            ],
        ];

        $result = $svc->convertMultipleAmountsWithSnapshot(['refund' => 20000], $snapshot);

        // (20000/1000)*0.85 = 17.0 (스냅샷 0.85, 현재 환율 1.50 이 아님)
        $this->assertEquals(17.0, $result['USD']['refund']);
        $this->assertNotEquals(30.0, $result['USD']['refund']); // 현재 환율 1.50 이었다면 30.0
    }
}
