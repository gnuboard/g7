<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use App\Upgrades\Data\Ext\Plugins\SirsoftPayKginicis\V1_0_1\Migrations\BackfillEasyPayPgProvider;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

require_once dirname(__DIR__, 3).'/upgrades/data/1.0.1/migrations/BackfillEasyPayPgProvider.php';

/**
 * 저장된 주문설정의 KG 이니시스 간편결제 PG 고정 백필 테스트 — 이슈 #475
 *
 * 기존 설치 환경의 order_settings.json 에는 간편결제가 `pg_provider: null` 로 영속화되어
 * 있다. 서버가 이 값을 보고 간편결제 주문을 "PG 결제가 아닌 주문" 으로 오인해 결제 실패
 * 시 관리자 알림 오발송 + 임시주문 삭제(재결제 불가) 를 일으켰다.
 */
class BackfillEasyPayPgProviderTest extends TestCase
{
    private string $settingsPath;

    private bool $hadOriginalSettings = false;

    private ?string $originalSettings = null;

    protected function setUp(): void
    {
        parent::setUp();

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

    /**
     * @scenario method_kind=extension, capability_declared=declared, capability=pg_locked
     *
     * @effects upgrade_step_backfills_settings_file, saved_null_pg_provider_self_healed
     */
    public function test_backfills_pg_declaration_for_kginicis_easy_pay_methods(): void
    {
        // 결함 시절의 저장 상태 재현: pg_provider=null, 능력 키 부재
        $this->writeSettings([
            ['id' => 'card', 'pg_provider' => 'kginicis'],
            ['id' => 'kginicis_naverpay', 'pg_provider' => null, 'is_active' => true],
            ['id' => 'kginicis_lpay', 'pg_provider' => null, 'is_active' => true],
        ]);

        $this->runMigration();

        $methods = $this->readMethods();

        foreach (['kginicis_naverpay', 'kginicis_lpay'] as $id) {
            $method = $methods[$id];
            $this->assertSame('kginicis', $method['pg_provider'], "{$id} 의 PG 가 고정되어야 한다");
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
            ['id' => 'card', 'pg_provider' => 'kginicis'],
            ['id' => 'dbank', 'pg_provider' => ''],
            ['id' => 'nhnkcp_naverpay', 'pg_provider' => null],
            ['id' => 'kginicis_naverpay', 'pg_provider' => null],
        ]);

        $this->runMigration();

        $methods = $this->readMethods();

        $this->assertSame('kginicis', $methods['card']['pg_provider']);
        $this->assertArrayNotHasKey('pg_locked', $methods['card']);

        $this->assertSame('', $methods['dbank']['pg_provider']);
        $this->assertArrayNotHasKey('pg_locked', $methods['dbank']);

        // 다른 PG 플러그인의 수단은 그 플러그인의 스텝이 처리한다.
        $this->assertNull($methods['nhnkcp_naverpay']['pg_provider']);
        $this->assertArrayNotHasKey('pg_locked', $methods['nhnkcp_naverpay']);

        $this->assertSame('kginicis', $methods['kginicis_naverpay']['pg_provider']);
    }

    /**
     * @scenario method_kind=extension, capability_declared=declared, capability=pg_locked
     *
     * @effects upgrade_step_backfills_settings_file
     */
    public function test_is_idempotent(): void
    {
        $this->writeSettings([
            ['id' => 'kginicis_naverpay', 'pg_provider' => null],
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
     * @param  array<int, array<string, mixed>>  $paymentMethods
     */
    private function writeSettings(array $paymentMethods): void
    {
        File::put($this->settingsPath, json_encode([
            'payment_methods' => $paymentMethods,
        ], JSON_THROW_ON_ERROR));
    }

    /**
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

    private function runMigration(): void
    {
        (new BackfillEasyPayPgProvider)->run(new UpgradeContext(
            fromVersion: '1.0.0',
            toVersion: '1.0.1',
            currentStep: '1.0.1',
        ));
    }
}
