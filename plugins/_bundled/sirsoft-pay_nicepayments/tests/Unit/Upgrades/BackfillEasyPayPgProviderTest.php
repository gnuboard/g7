<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use App\Upgrades\Data\Ext\Plugins\SirsoftPayNicepayments\V1_0_0_beta_4\Migrations\BackfillEasyPayPgProvider;
use Illuminate\Support\Facades\File;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;
use Tests\Support\UpgradeFiles;

UpgradeFiles::requireOnce(dirname(__DIR__, 3).'/upgrades/data/1.0.0-beta.4/migrations/BackfillEasyPayPgProvider.php');

/**
 * 저장된 주문설정의 나이스페이먼츠 간편결제 PG 고정 백필 테스트 — 이슈 #475
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

    /**
     * 이커머스 주문설정 파일 경로를 잡고 기존 원본 내용을 백업한 뒤 설정 디렉터리를 마련한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsPath = ExtensionStoragePath::module('sirsoft-ecommerce', 'settings').'/order_settings.json';
        $this->hadOriginalSettings = File::exists($this->settingsPath);
        $this->originalSettings = $this->hadOriginalSettings ? File::get($this->settingsPath) : null;

        File::ensureDirectoryExists(dirname($this->settingsPath));
    }

    /**
     * 백업해 둔 원본 주문설정을 되돌리고, 원본이 없었으면 테스트가 만든 파일을 지운다.
     *
     * @return void 반환값 없음
     */
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
     * pg_provider 가 null 로 저장된 나이스페이먼츠 간편결제 수단에 PG·pg_locked·needs_pg·환불방식을 채우고 관리자 활성값은 보존한다.
     *
     * @scenario method_kind=extension, capability_declared=declared, capability=pg_locked
     *
     * @effects upgrade_step_backfills_settings_file, saved_null_pg_provider_self_healed
     *
     * @return void 반환값 없음
     */
    public function test_backfills_pg_declaration_for_nicepay_easy_pay_methods(): void
    {
        $this->writeSettings([
            ['id' => 'card', 'pg_provider' => 'nicepayments'],
            ['id' => 'nicepay_naverpay', 'pg_provider' => null, 'is_active' => true],
            ['id' => 'nicepay_kakaopay', 'pg_provider' => null, 'is_active' => true],
        ]);

        $this->runMigration();

        $methods = $this->readMethods();

        foreach (['nicepay_naverpay', 'nicepay_kakaopay'] as $id) {
            $method = $methods[$id];
            $this->assertSame('nicepayments', $method['pg_provider'], "{$id} 의 PG 가 고정되어야 한다");
            $this->assertTrue($method['pg_locked']);
            $this->assertTrue($method['needs_pg']);
            $this->assertSame('pg', $method['refund_method']);
            $this->assertTrue($method['is_active']);
        }
    }

    /**
     * 자기 접두사(nicepay_) 수단만 백필하고 카드·무통장 등 내장 수단과 다른 PG 플러그인 수단은 건드리지 않는다.
     *
     * @scenario case=other_methods
     *
     * @effects other_plugin_and_builtin_methods_untouched
     *
     * @return void 반환값 없음
     */
    public function test_does_not_touch_other_plugins_or_builtin_methods(): void
    {
        $this->writeSettings([
            ['id' => 'card', 'pg_provider' => 'nicepayments'],
            ['id' => 'dbank', 'pg_provider' => ''],
            ['id' => 'nhnkcp_naverpay', 'pg_provider' => null],
            ['id' => 'nicepay_naverpay', 'pg_provider' => null],
        ]);

        $this->runMigration();

        $methods = $this->readMethods();

        $this->assertSame('nicepayments', $methods['card']['pg_provider']);
        $this->assertArrayNotHasKey('pg_locked', $methods['card']);

        $this->assertSame('', $methods['dbank']['pg_provider']);
        $this->assertArrayNotHasKey('pg_locked', $methods['dbank']);

        $this->assertNull($methods['nhnkcp_naverpay']['pg_provider']);
        $this->assertArrayNotHasKey('pg_locked', $methods['nhnkcp_naverpay']);

        $this->assertSame('nicepayments', $methods['nicepay_naverpay']['pg_provider']);
    }

    /**
     * 백필 스텝을 두 번 실행해도 주문설정 파일 내용이 첫 실행 결과와 같다.
     *
     * @scenario method_kind=extension, capability_declared=declared, capability=pg_locked
     *
     * @effects upgrade_step_backfills_settings_file
     *
     * @return void 반환값 없음
     */
    public function test_is_idempotent(): void
    {
        $this->writeSettings([
            ['id' => 'nicepay_naverpay', 'pg_provider' => null],
        ]);

        $this->runMigration();
        $first = File::get($this->settingsPath);

        $this->runMigration();
        $second = File::get($this->settingsPath);

        $this->assertSame($first, $second, '재실행해도 결과가 달라지지 않아야 한다');
    }

    /**
     * 주문설정 파일이 없으면 스텝이 건너뛰고 새 파일을 만들지 않는다.
     *
     * @scenario case=settings_missing
     *
     * @effects missing_settings_file_skipped
     *
     * @return void 반환값 없음
     */
    public function test_skips_when_settings_file_is_missing(): void
    {
        File::delete($this->settingsPath);

        $this->runMigration();

        $this->assertFalse(File::exists($this->settingsPath));
    }

    /**
     * 주문설정 JSON 이 깨져 있으면 스텝이 건너뛰고 손상된 원문을 덮어쓰지 않는다.
     *
     * @scenario case=settings_malformed
     *
     * @effects malformed_settings_json_preserved
     *
     * @return void 반환값 없음
     */
    public function test_skips_when_settings_json_is_malformed(): void
    {
        File::put($this->settingsPath, '{ not valid json');

        $this->runMigration();

        $this->assertSame('{ not valid json', File::get($this->settingsPath));
    }

    /**
     * 주어진 결제수단 목록을 payment_methods 로 담아 주문설정 파일에 씁니다.
     *
     * @param  array<int, array<string, mixed>>  $paymentMethods  저장할 결제수단 설정 목록
     * @return void 반환값 없음
     */
    private function writeSettings(array $paymentMethods): void
    {
        File::put($this->settingsPath, json_encode([
            'payment_methods' => $paymentMethods,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * 주문설정 파일의 payment_methods 를 읽어 결제수단 ID 를 키로 하는 맵으로 바꿉니다.
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
     * 업그레이드 컨텍스트를 만들어 간편결제 PG 백필 마이그레이션을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runMigration(): void
    {
        (new BackfillEasyPayPgProvider)->run(new UpgradeContext(
            fromVersion: '1.0.0-beta.3',
            toVersion: '1.0.0-beta.4',
            currentStep: '1.0.0-beta.4',
        ));
    }
}
