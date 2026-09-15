<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Support\CurrencySettingsCache;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 통화 삭제 후 기존 주문의 금액·표기 불변성 테스트 (공개 #91 후속)
 *
 * 관리자가 통화를 삭제해도 그 통화로 결제된 과거 주문은 환불 재계산·표기 모두 주문 시점
 * 스냅샷을 따라야 한다. 환산 금액은 스냅샷 환율·절사규칙을 쓰므로 이미 불변이지만,
 * 소수 자릿수만은 현재 설정에서 조회해 왔다 — 삭제된 통화는 설정에서 사라지므로
 * 폴백 2자리가 적용되어 0자리 통화(JPY 등)의 표기가 `¥18,860` → `¥18,860.00` 으로 바뀌고,
 * 3자리 이상으로 설정된 통화는 표시 금액이 절사된다.
 */
class DeletedCurrencyOrderIntegrityTest extends ModuleTestCase
{
    private string $storagePath;

    /**
     * 주문 시점 스냅샷 — JPY(0자리)·XAU(3자리)가 살아 있던 시점.
     */
    private const SNAPSHOT = [
        'base_currency' => 'KRW',
        'order_currency' => 'JPY',
        'exchange_rate' => 115.0,
        'base_unit' => 1000,
        'exchange_rates' => [
            'KRW' => ['rate' => 1.0, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0, 'base_unit' => 1000],
            'JPY' => ['rate' => 115.0, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0, 'base_unit' => 100],
            'XAU' => ['rate' => 0.002, 'rounding_unit' => '0.001', 'rounding_method' => 'round', 'decimal_places' => 3, 'base_unit' => 1],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');

        // 현재 설정 = 관리자가 JPY·XAU 를 삭제한 뒤 (KRW 만 남음)
        File::ensureDirectoryExists($this->storagePath);
        File::put(
            $this->storagePath.'/language_currency.json',
            json_encode([
                'default_currency' => 'KRW',
                'currencies' => [
                    [
                        'code' => 'KRW',
                        'name' => ['ko' => 'KRW (원)', 'en' => 'KRW (Won)'],
                        'exchange_rate' => null,
                        'base_unit' => 1000,
                        'rounding_unit' => '1',
                        'rounding_method' => 'floor',
                        'decimal_places' => 0,
                        'is_default' => true,
                    ],
                ],
                'removed_default_currencies' => ['USD', 'JPY', 'CNY', 'EUR'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        config(['g7_settings.modules.sirsoft-ecommerce.language_currency' => [
            'default_currency' => 'KRW',
            'currencies' => [
                ['code' => 'KRW', 'name' => ['ko' => 'KRW', 'en' => 'KRW'], 'exchange_rate' => null, 'base_unit' => 1000, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0, 'is_default' => true],
            ],
        ]]);

        CurrencySettingsCache::clear();
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }
        CurrencySettingsCache::clear();

        parent::tearDown();
    }

    /**
     * 리소스 계층(트레이트)을 단독으로 쓰기 위한 익명 클래스를 만듭니다.
     */
    private function resourceStub(): object
    {
        return new class
        {
            use HasMultiCurrencyPrices;

            public function formatStored(?array $amounts): array
            {
                return $this->formatStoredMultiCurrency($amounts);
            }

            public function roundTo(float|int|null $price, string $code): float|int
            {
                return $this->roundToCurrency($price, $code);
            }
        };
    }

    // ──────────────────────────────────────────────
    // 환불 재계산 금액 (스냅샷 고정 — 회귀 방지)
    // ──────────────────────────────────────────────

    /**
     * 삭제된 통화로 결제된 주문의 부분환불 금액이 스냅샷 환율로 계산된다.
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=has_removed_codes
     *
     * @effects deleted_currency_order_refund_uses_snapshot_rate
     */
    public function test_partial_refund_of_deleted_currency_order_uses_snapshot_rate(): void
    {
        /** @var CurrencyConversionService $svc */
        $svc = app(CurrencyConversionService::class);

        // 부분환불 10,000원 → JPY 환산 = (10000/1000) * 115 = 1,150
        $result = $svc->convertMultipleAmountsWithSnapshot(['refund_amount' => 10000], self::SNAPSHOT);

        $this->assertArrayHasKey('JPY', $result, '삭제된 통화가 스냅샷 환산 결과에서 사라졌습니다.');
        $this->assertEquals(1150, $result['JPY']['refund_amount']);
        $this->assertEquals(10000, $result['KRW']['refund_amount']);
    }

    /**
     * 삭제된 통화의 결제 청구액도 스냅샷 환율·자릿수로 산출된다.
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=has_removed_codes
     *
     * @effects deleted_currency_order_refund_uses_snapshot_rate
     */
    public function test_payment_charge_of_deleted_currency_uses_snapshot(): void
    {
        /** @var CurrencyConversionService $svc */
        $svc = app(CurrencyConversionService::class);

        $charge = $svc->resolveSnapshotPaymentCharge(10000, self::SNAPSHOT);

        $this->assertSame('JPY', $charge['currency']);
        $this->assertEquals(1150, $charge['amount']);
    }

    // ──────────────────────────────────────────────
    // 자릿수 — 스냅샷 우선 (이번 수정 대상)
    // ──────────────────────────────────────────────

    /**
     * 삭제된 0자리 통화의 환산 표기가 스냅샷 자릿수(0)를 유지한다.
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=has_removed_codes
     *
     * @effects deleted_currency_display_keeps_snapshot_decimal_places
     */
    public function test_converted_amount_formatting_keeps_snapshot_decimal_places(): void
    {
        /** @var CurrencyConversionService $svc */
        $svc = app(CurrencyConversionService::class);

        $result = $svc->convertMultipleAmountsWithSnapshot(['refund_amount' => 10000], self::SNAPSHOT);

        $this->assertStringNotContainsString(
            '.00',
            $result['JPY']['refund_amount_formatted'],
            '삭제된 0자리 통화가 폴백 2자리로 표기되었습니다.'
        );
    }

    /**
     * 삭제된 3자리 통화의 표기가 절사되지 않는다.
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=has_removed_codes
     *
     * @effects deleted_currency_display_keeps_snapshot_decimal_places
     */
    public function test_high_precision_deleted_currency_is_not_truncated(): void
    {
        /** @var CurrencyConversionService $svc */
        $svc = app(CurrencyConversionService::class);

        // (10000/1000) * 0.002 = 0.02 → 절사단위 0.001 → 0.02, 3자리 표기 '0.020'
        $result = $svc->convertMultipleAmountsWithSnapshot(['refund_amount' => 10000], self::SNAPSHOT);

        $this->assertStringContainsString(
            '0.020',
            $result['XAU']['refund_amount_formatted'],
            '삭제된 3자리 통화가 폴백 2자리로 절사되었습니다.'
        );
    }

    /**
     * 서비스의 단일 금액 포맷도 스냅샷 자릿수를 따른다.
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=has_removed_codes
     *
     * @effects deleted_currency_display_keeps_snapshot_decimal_places
     */
    public function test_format_price_accepts_snapshot(): void
    {
        /** @var CurrencyConversionService $svc */
        $svc = app(CurrencyConversionService::class);

        $this->assertSame('¥18,860', $svc->formatPrice(18860, 'JPY', self::SNAPSHOT));
        // 스냅샷 없이 호출하면 현행대로 현재 설정 폴백(2자리)
        $this->assertSame('¥18,860.00', $svc->formatPrice(18860, 'JPY'));
    }

    /**
     * 주문의 저장된 mc_* 금액 표기가 스냅샷 자릿수를 따른다.
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=has_removed_codes
     *
     * @effects deleted_currency_display_keeps_snapshot_decimal_places
     */
    public function test_stored_multi_currency_formatting_keeps_snapshot_decimal_places(): void
    {
        $resource = $this->resourceStub()->withCurrencySnapshot(self::SNAPSHOT);

        $formatted = $resource->formatStored(['KRW' => 129000, 'JPY' => 14835]);

        $this->assertSame('129,000원', $formatted['KRW']['formatted']);
        $this->assertSame('¥14,835', $formatted['JPY']['formatted'], '삭제된 0자리 통화가 폴백 2자리로 표기되었습니다.');
    }

    /**
     * raw 금액 라운딩도 스냅샷 자릿수를 따른다 (0자리 통화는 int 유지).
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=has_removed_codes
     *
     * @effects deleted_currency_display_keeps_snapshot_decimal_places
     */
    public function test_round_to_currency_keeps_snapshot_decimal_places(): void
    {
        $resource = $this->resourceStub()->withCurrencySnapshot(self::SNAPSHOT);

        $this->assertSame(14835, $resource->roundTo(14835.0, 'JPY'), '삭제된 0자리 통화가 float 로 반환되었습니다.');
    }

    // ──────────────────────────────────────────────
    // 폴백 (레거시 스냅샷 / 스냅샷 부재)
    // ──────────────────────────────────────────────

    /**
     * 자릿수가 없는 구형 스냅샷은 현재 설정 폴백을 그대로 유지한다.
     *
     * @scenario saved_currency_set=default_removed_by_admin, deletion_tombstone=none_legacy
     *
     * @effects legacy_snapshot_without_decimal_places_falls_back_to_settings
     */
    public function test_legacy_snapshot_without_decimal_places_falls_back(): void
    {
        /** @var CurrencyConversionService $svc */
        $svc = app(CurrencyConversionService::class);

        // 구형 스냅샷: 환율이 단순 숫자 (절사규칙·자릿수 미박제)
        $legacy = [
            'base_currency' => 'KRW',
            'order_currency' => 'JPY',
            'exchange_rates' => ['JPY' => 115.0],
        ];

        $this->assertSame('¥18,860.00', $svc->formatPrice(18860, 'JPY', $legacy));
    }

    /**
     * 스냅샷을 주지 않은 리소스는 현재 설정으로 동작한다 (상품 등 카탈로그 경로 회귀 방지).
     *
     * @scenario saved_currency_set=all_five, deletion_tombstone=none_legacy
     *
     * @effects legacy_snapshot_without_decimal_places_falls_back_to_settings
     */
    public function test_resource_without_snapshot_uses_live_settings(): void
    {
        $resource = $this->resourceStub();

        // KRW 는 현재 설정에 살아 있고 0자리
        $formatted = $resource->formatStored(['KRW' => 129000]);

        $this->assertSame('129,000원', $formatted['KRW']['formatted']);
    }
}
