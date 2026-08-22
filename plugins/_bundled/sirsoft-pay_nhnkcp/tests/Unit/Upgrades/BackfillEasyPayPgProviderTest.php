<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use App\Upgrades\Data\Ext\Plugins\SirsoftPayNhnkcp\V1_0_0_beta_4\Migrations\BackfillEasyPayPgProvider;
use Illuminate\Support\Facades\File;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

require_once dirname(__DIR__, 3).'/upgrades/data/1.0.0-beta.4/migrations/BackfillEasyPayPgProvider.php';

/**
 * 저장된 주문설정의 NHN KCP 간편결제 PG 고정 백필 테스트 — 이슈 #475
 *
 * 기존 설치 환경의 order_settings.json 에는 간편결제가 `pg_provider: null` 로 영속화되어
 * 있다. 서버가 이 값을 보고 간편결제 주문을 "PG 결제가 아닌 주문" 으로 오인해 결제 실패
 * 시 관리자 알림 오발송 + 임시주문 삭제(재결제 불가) 를 일으켰다.
 */
class BackfillEasyPayPgProviderTest extends PluginTestCase
{
    private string $settingsPath;

    private bool $hadOriginalSettings = false;

    private ?string $originalSettings = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsPath = ExtensionStoragePath::module('sirsoft-ecommerce', 'settings').'/order_settings.json';
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
    public function test_backfills_pg_declaration_for_nhnkcp_easy_pay_methods(): void
    {
        $this->writeSettings([
            ['id' => 'card', 'pg_provider' => 'nhnkcp'],
            ['id' => 'nhnkcp_naverpay', 'pg_provider' => null, 'is_active' => true],
            ['id' => 'nhnkcp_applepay', 'pg_provider' => null, 'is_active' => true],
        ]);

        $this->runMigration();

        $methods = $this->readMethods();

        foreach (['nhnkcp_naverpay', 'nhnkcp_applepay'] as $id) {
            $method = $methods[$id];
            $this->assertSame('nhnkcp', $method['pg_provider'], "{$id} 의 PG 가 고정되어야 한다");
            $this->assertTrue($method['pg_locked']);
            $this->assertTrue($method['needs_pg']);
            $this->assertSame('pg', $method['refund_method']);
            $this->assertTrue($method['is_active']);
        }
    }

    public function test_does_not_touch_other_plugins_or_builtin_methods(): void
    {
        $this->writeSettings([
            ['id' => 'card', 'pg_provider' => 'nhnkcp'],
            ['id' => 'dbank', 'pg_provider' => ''],
            ['id' => 'kginicis_naverpay', 'pg_provider' => null],
            ['id' => 'nhnkcp_naverpay', 'pg_provider' => null],
        ]);

        $this->runMigration();

        $methods = $this->readMethods();

        $this->assertSame('nhnkcp', $methods['card']['pg_provider']);
        $this->assertArrayNotHasKey('pg_locked', $methods['card']);

        $this->assertSame('', $methods['dbank']['pg_provider']);
        $this->assertArrayNotHasKey('pg_locked', $methods['dbank']);

        $this->assertNull($methods['kginicis_naverpay']['pg_provider']);
        $this->assertArrayNotHasKey('pg_locked', $methods['kginicis_naverpay']);

        $this->assertSame('nhnkcp', $methods['nhnkcp_naverpay']['pg_provider']);
    }

    /**
     * @scenario method_kind=extension, capability_declared=declared, capability=pg_locked
     *
     * @effects upgrade_step_backfills_settings_file
     */
    public function test_is_idempotent(): void
    {
        $this->writeSettings([
            ['id' => 'nhnkcp_naverpay', 'pg_provider' => null],
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
            fromVersion: '1.0.0-beta.3',
            toVersion: '1.0.0-beta.4',
            currentStep: '1.0.0-beta.4',
        ));
    }
}
