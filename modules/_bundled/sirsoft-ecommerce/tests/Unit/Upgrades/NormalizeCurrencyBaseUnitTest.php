<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * base_unit 정규화 마이그레이션 테스트 (MP08-3 방향 B)
 *
 * 핵심 불변식: 변환 전/후 환산(결제·환불) 금액이 동일해야 한다(환차손 0, D-BASE-3).
 */
class NormalizeCurrencyBaseUnitTest extends ModuleTestCase
{
    private const SETTINGS_NS = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_0_0\\Migrations\\NormalizeCurrencyBaseUnit';

    private const SNAPSHOT_NS = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_0_0\\Migrations\\NormalizeOrderSnapshotBaseUnit';

    private string $settingsPath;

    private string $settingsFile;

    protected function setUp(): void
    {
        parent::setUp();
        // DataMigration 클래스는 오토로드 밖 — 직접 로드
        $migDir = dirname(__DIR__, 3).'/upgrades/data/1.0.0/migrations';
        require_once $migDir.'/NormalizeCurrencyBaseUnit.php';
        require_once $migDir.'/NormalizeOrderSnapshotBaseUnit.php';

        // 운영 storage 오염 방지: 테스트 환경 경로 사용 (마이그레이션의 runningUnitTests 분기와 일치)
        $this->settingsPath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        $this->settingsFile = $this->settingsPath.'/language_currency.json';
    }

    protected function tearDown(): void
    {
        if (File::exists($this->settingsFile)) {
            File::delete($this->settingsFile);
        }
        parent::tearDown();
    }

    private function settingsMigration(): object
    {
        $class = self::SETTINGS_NS;

        return new $class;
    }

    private function snapshotMigration(): object
    {
        $class = self::SNAPSHOT_NS;

        return new $class;
    }

    private function context(): UpgradeContext
    {
        return new UpgradeContext(
            fromVersion: '1.0.0-beta.4',
            toVersion: '1.0.0',
            currentStep: '1.0.0',
        );
    }

    private function writeSettings(array $settings): void
    {
        File::ensureDirectoryExists($this->settingsPath);
        File::put($this->settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function readSettings(): array
    {
        return json_decode((string) File::get($this->settingsFile), true);
    }

    // ──────────────────────────────────────────────
    // 설정 정규화 (NormalizeCurrencyBaseUnit)
    // ──────────────────────────────────────────────

    public function test_usd_base_settings_normalize_rate_and_assign_base_unit(): void
    {
        // 현 워크스페이스 형태: base=USD, 환율 = "1000달러당 N"(÷1000 전제)
        $this->writeSettings([
            'default_currency' => 'USD',
            'currencies' => [
                ['code' => 'USD', 'exchange_rate' => null, 'is_default' => true],
                ['code' => 'JPY', 'exchange_rate' => 157000, 'is_default' => false],
                ['code' => 'KRW', 'exchange_rate' => 1176470, 'is_default' => false],
                ['code' => 'EUR', 'exchange_rate' => 920, 'is_default' => false],
            ],
        ]);

        ($this->settingsMigration())->run($this->context());

        $out = $this->readSettings();
        $byCode = collect($out['currencies'])->keyBy('code');

        // base_unit 부여: USD=1, JPY=100, KRW=1000, EUR=1
        $this->assertSame(1, $byCode['USD']['base_unit']);
        $this->assertSame(100, $byCode['JPY']['base_unit']);
        $this->assertSame(1000, $byCode['KRW']['base_unit']);
        $this->assertSame(1, $byCode['EUR']['base_unit']);

        // 환율 정규화: scale = USD base_unit(1)/1000 = 0.001
        // JPY 157000 × 0.001 = 157, KRW 1176470 × 0.001 = 1176.47, EUR 920 × 0.001 = 0.92
        $this->assertSame(157, $byCode['JPY']['exchange_rate']);
        $this->assertEqualsWithDelta(1176.47, $byCode['KRW']['exchange_rate'], 0.001);
        $this->assertEqualsWithDelta(0.92, $byCode['EUR']['exchange_rate'], 0.0001);
        // 기본 통화 환율은 null 유지
        $this->assertNull($byCode['USD']['exchange_rate']);
    }

    public function test_krw_base_settings_keep_rate_unchanged(): void
    {
        // KRW base: base_unit=1000 → scale=1 → 환율 불변(등가)
        $this->writeSettings([
            'default_currency' => 'KRW',
            'currencies' => [
                ['code' => 'KRW', 'exchange_rate' => null, 'is_default' => true],
                ['code' => 'USD', 'exchange_rate' => 0.85, 'is_default' => false],
                ['code' => 'JPY', 'exchange_rate' => 115, 'is_default' => false],
            ],
        ]);

        ($this->settingsMigration())->run($this->context());

        $byCode = collect($this->readSettings()['currencies'])->keyBy('code');
        // scale = 1000/1000 = 1 → 환율 그대로
        $this->assertEqualsWithDelta(0.85, $byCode['USD']['exchange_rate'], 0.0001);
        $this->assertSame(115, $byCode['JPY']['exchange_rate']);
        $this->assertSame(1000, $byCode['KRW']['base_unit']);
    }

    public function test_settings_normalization_is_idempotent(): void
    {
        $this->writeSettings([
            'default_currency' => 'USD',
            'currencies' => [
                ['code' => 'USD', 'exchange_rate' => null, 'is_default' => true, 'base_unit' => 1],
                ['code' => 'JPY', 'exchange_rate' => 157, 'is_default' => false, 'base_unit' => 100],
            ],
        ]);

        ($this->settingsMigration())->run($this->context());

        $byCode = collect($this->readSettings()['currencies'])->keyBy('code');
        // 이미 base_unit 있으면 no-op → 157 그대로 (두 번 ÷1000 되지 않음)
        $this->assertSame(157, $byCode['JPY']['exchange_rate']);
    }

    // ──────────────────────────────────────────────
    // 스냅샷 정규화 등가성 (NormalizeOrderSnapshotBaseUnit) — 환차손 0
    // ──────────────────────────────────────────────

    public function test_snapshot_normalization_preserves_conversion_result(): void
    {
        $svc = app(CurrencyConversionService::class);

        // 옛 스냅샷: base=USD, JPY rate=157000 (÷1000 전제), base_unit 없음
        $oldSnapshot = [
            'base_currency' => 'USD',
            'order_currency' => 'JPY',
            'exchange_rate' => 157000,
            'exchange_rates' => [
                'USD' => ['rate' => 1, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2],
                'JPY' => ['rate' => 157000, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0],
                'KRW' => ['rate' => 1176470, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0],
            ],
        ];

        // 변환 전 환산: $6 → JPY (폴백 1000) = (6/1000)×157000 = 942
        $before = $svc->resolveSnapshotPaymentCharge(6.0, $oldSnapshot);
        $this->assertSame(942, $before['minor_unit_amount']);

        // 마이그레이션의 정규화 로직을 리플렉션으로 직접 적용
        $migration = $this->snapshotMigration();
        $method = new \ReflectionMethod($migration, 'normalizeSnapshot');
        $method->setAccessible(true);
        $newSnapshot = $method->invoke($migration, $oldSnapshot);

        // 박제 확인: base_unit=1(USD), JPY rate=157
        $this->assertSame(1, $newSnapshot['base_unit']);
        $this->assertSame(157, $newSnapshot['exchange_rates']['JPY']['rate']);
        $this->assertSame(100, $newSnapshot['exchange_rates']['JPY']['base_unit']);

        // 변환 후 환산: $6 → JPY (base_unit 1) = (6/1)×157 = 942 (동일!)
        $after = $svc->resolveSnapshotPaymentCharge(6.0, $newSnapshot);
        $this->assertSame(942, $after['minor_unit_amount'], '변환 전/후 결제금액이 달라짐 — 환차손 0 위반');
        $this->assertSame($before['minor_unit_amount'], $after['minor_unit_amount']);
    }

    public function test_snapshot_refund_amount_unchanged_after_normalization(): void
    {
        $svc = app(CurrencyConversionService::class);

        $oldSnapshot = [
            'base_currency' => 'USD',
            'order_currency' => 'KRW',
            'exchange_rates' => [
                'USD' => ['rate' => 1, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2],
                'KRW' => ['rate' => 1176470, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0],
            ],
        ];

        // 환불 $6 → KRW (폴백 1000) = (6/1000)×1176470 = 7058 (floor)
        $before = $svc->convertMultipleAmountsWithSnapshot(['refund' => 6], $oldSnapshot);

        $migration = $this->snapshotMigration();
        $method = new \ReflectionMethod($migration, 'normalizeSnapshot');
        $method->setAccessible(true);
        $newSnapshot = $method->invoke($migration, $oldSnapshot);

        // 변환 후 환불 = (6/1)×1176.47 = 7058.82 → floor 7058 (동일)
        $after = $svc->convertMultipleAmountsWithSnapshot(['refund' => 6], $newSnapshot);

        $this->assertSame($before['KRW']['refund'], $after['KRW']['refund'], '환불 금액이 변환 후 달라짐 — 환차손 0 위반');
    }

    public function test_snapshot_run_is_idempotent_via_db(): void
    {
        // run() 의 base_unit 가드가 이중 정규화를 막는지 DB 로 검증 (rate 가 두 번 ÷1000 되면 안 됨)
        $order = Order::factory()->create([
            'currency' => 'JPY',
            'currency_snapshot' => [
                'base_currency' => 'USD',
                'order_currency' => 'JPY',
                'exchange_rate' => 157000,
                'exchange_rates' => [
                    'USD' => ['rate' => 1, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2],
                    'JPY' => ['rate' => 157000, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0],
                ],
            ],
        ]);

        $migration = $this->snapshotMigration();
        $migration->run($this->context());
        $migration->run($this->context()); // 2회차 = no-op 이어야 함

        $snap = json_decode((string) DB::table('ecommerce_orders')->where('id', $order->id)->value('currency_snapshot'), true);

        // 1회만 정규화되어 JPY rate=157 (이중 적용되면 0.157→round→0)
        $this->assertSame(157, $snap['exchange_rates']['JPY']['rate']);
        $this->assertSame(1, $snap['base_unit']);
    }
}
