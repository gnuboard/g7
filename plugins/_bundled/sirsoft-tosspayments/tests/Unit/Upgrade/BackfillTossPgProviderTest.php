<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Tests\Unit\Upgrade;

use App\Extension\UpgradeContext;
use App\Upgrades\Data\Ext\Plugins\SirsoftTosspayments\V1_0_1\Migrations\BackfillTossPgProvider;
use Illuminate\Support\Facades\File;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 저장된 주문설정의 토스 주문서형 결제수단 PG 고정 백필.
 *
 * 주문서형 결제수단(toss_*)은 `pg_provider: null` 로 등록되어 저장 파일에 그대로
 * 영속화됐고, 관리자 주문설정 화면이 다른 간편결제와 달리 "PG 고정" 배지 대신 빈 PG
 * 선택 셀렉트를 그렸다. 백필은 자기 접두사 수단만, 멱등하게 현재 선언과 일치시킨다.
 *
 * @scenario method_kind=extension, capability_declared=declared, capability=pg_locked
 *
 * @effects upgrade_step_backfills_settings_file, saved_null_pg_provider_self_healed
 *
 * @group payment
 * @group upgrade
 */
class BackfillTossPgProviderTest extends PluginTestCase
{
    private string $settingsPath;

    private bool $hadOriginalSettings = false;

    private ?string $originalSettings = null;

    protected function setUp(): void
    {
        parent::setUp();

        require_once base_path('plugins/_bundled/sirsoft-tosspayments/upgrades/data/1.0.1/migrations/BackfillTossPgProvider.php');

        $this->settingsPath = storage_path('app/modules/sirsoft-ecommerce/settings/order_settings.json');
        $this->hadOriginalSettings = File::exists($this->settingsPath);
        $this->originalSettings = $this->hadOriginalSettings ? File::get($this->settingsPath) : null;

        File::ensureDirectoryExists(dirname($this->settingsPath));
    }

    protected function tearDown(): void
    {
        if ($this->hadOriginalSettings && $this->originalSettings !== null) {
            File::put($this->settingsPath, $this->originalSettings);
        } else {
            File::delete($this->settingsPath);
        }

        parent::tearDown();
    }

    public function test_backfills_pg_declaration_for_toss_methods(): void
    {
        // 결함 시절의 저장 상태 재현: pg_provider=null, 능력 키 부재
        $this->writeSettings([
            ['id' => 'card', 'pg_provider' => 'tosspayments'],
            ['id' => 'toss_tosspay', 'pg_provider' => null, 'is_active' => true],
            ['id' => 'toss_naverpay', 'pg_provider' => null, 'is_active' => true],
        ]);

        $this->runMigration();

        $methods = $this->readMethods();

        foreach (['toss_tosspay', 'toss_naverpay'] as $id) {
            $method = $methods[$id];
            $this->assertSame('tosspayments', $method['pg_provider'], "{$id} 의 PG 가 고정되어야 한다");
            $this->assertTrue($method['pg_locked']);
            $this->assertTrue($method['needs_pg']);
            $this->assertSame('pg', $method['refund_method']);
            // 관리자가 설정한 값은 보존한다.
            $this->assertTrue($method['is_active']);
        }
    }

    public function test_does_not_touch_other_plugins_or_builtin_methods(): void
    {
        // 각 플러그인은 자기 접두사 수단만 백필한다 (다른 PG 의 수단을 건드리면 안 된다).
        $this->writeSettings([
            ['id' => 'card', 'pg_provider' => 'tosspayments'],
            ['id' => 'dbank', 'pg_provider' => ''],
            ['id' => 'kginicis_naverpay', 'pg_provider' => null],
            ['id' => 'toss_card', 'pg_provider' => null],
        ]);

        $this->runMigration();

        $methods = $this->readMethods();

        $this->assertSame('tosspayments', $methods['card']['pg_provider']);
        $this->assertArrayNotHasKey('pg_locked', $methods['card']);

        $this->assertSame('', $methods['dbank']['pg_provider']);
        $this->assertArrayNotHasKey('pg_locked', $methods['dbank']);

        // 다른 PG 플러그인의 수단은 그 플러그인의 스텝이 처리한다.
        $this->assertNull($methods['kginicis_naverpay']['pg_provider']);
        $this->assertArrayNotHasKey('pg_locked', $methods['kginicis_naverpay']);

        $this->assertSame('tosspayments', $methods['toss_card']['pg_provider']);
    }

    public function test_is_idempotent(): void
    {
        $this->writeSettings([
            ['id' => 'toss_card', 'pg_provider' => null],
        ]);

        $this->runMigration();
        $first = File::get($this->settingsPath);

        $this->runMigration();
        $second = File::get($this->settingsPath);

        $this->assertSame($first, $second, '재실행해도 결과가 달라지지 않아야 한다');
    }

    public function test_skips_when_settings_file_is_missing(): void
    {
        File::delete($this->settingsPath);

        $this->runMigration();

        $this->assertFalse(File::exists($this->settingsPath));
    }

    public function test_skips_when_settings_json_is_malformed(): void
    {
        File::put($this->settingsPath, '{ not valid json');

        $this->runMigration();

        // 손상된 파일을 덮어써서 더 망가뜨리지 않는다.
        $this->assertSame('{ not valid json', File::get($this->settingsPath));
    }

    /**
     * 테스트용 주문설정 파일을 기록합니다.
     *
     * @param  array<int, array<string, mixed>>  $paymentMethods  결제수단 배열
     */
    private function writeSettings(array $paymentMethods): void
    {
        File::put($this->settingsPath, json_encode([
            'payment_methods' => $paymentMethods,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * 저장 파일의 결제수단을 ID 키 맵으로 읽습니다.
     *
     * @return array<string, array<string, mixed>> 결제수단 ID => 설정
     */
    private function readMethods(): array
    {
        $settings = json_decode(File::get($this->settingsPath), true, flags: JSON_THROW_ON_ERROR);

        $keyed = [];
        foreach ($settings['payment_methods'] as $method) {
            $keyed[$method['id']] = $method;
        }

        return $keyed;
    }

    /**
     * 백필 마이그레이션을 실행합니다.
     */
    private function runMigration(): void
    {
        (new BackfillTossPgProvider)->run(new UpgradeContext(
            fromVersion: '1.0.0',
            toVersion: '1.0.1',
            currentStep: '1.0.1',
        ));
    }
}
