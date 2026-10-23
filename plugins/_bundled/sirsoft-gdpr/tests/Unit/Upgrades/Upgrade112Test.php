<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use App\Upgrades\Data\Ext\Plugins\SirsoftGdpr\V1_1_2\Migrations\RemoveComputedNecessaryStorageKeys;
use App\Upgrades\Data\Ext\Plugins\SirsoftGdpr\V1_1_2\Migrations\RemoveEcommerceOrderKeysFromNecessaryAllowlist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Plugins\Sirsoft\Gdpr\Plugin;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 1.1.2 업그레이드 스텝 — 설정 파일에 굳은 계산값 키·이커머스 주문 열 키 정리
 *
 * 01: 설치 때 설정 파일에 굳은 계산값 키(잠금·제공 목록)를 지운다 — 1.1.2 부터 코어가 저장하지 않고 늘 실시간 값을 쓴다.
 * 02: 1.0.4 가 시드한 `g7_order_*` 를 허용목록에서 걷어낸다 — 이커머스(1.3.2+)가 계약 훅으로 제공한다.
 *     이커머스가 아직 옛 버전이면 남긴다.
 * 둘 다 멱등이고 다른 설정·운영자 항목은 보존한다.
 *
 * @scenario scope=localStorage, notation=wildcard, locked=operator_item, settings_state=populated, request=key_absent
 *
 * @effects computed_keys_removed_from_saved_settings, ecommerce_order_keys_removed_from_allowlist, shipped_catalog_has_no_ecommerce_order_keys
 */
class Upgrade112Test extends PluginTestCase
{
    /** @var string 설정 파일 절대 경로 */
    private string $path;

    /**
     * 마이그레이션을 적재하고 잔여 설정 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__.'/../../../upgrades/data/1.1.2/migrations/01_RemoveComputedNecessaryStorageKeys.php';
        require_once __DIR__.'/../../../upgrades/data/1.1.2/migrations/02_RemoveEcommerceOrderKeysFromNecessaryAllowlist.php';

        $this->path = ExtensionStoragePath::plugin('sirsoft-gdpr', 'settings').'/setting.json';
        File::delete($this->path);
        DB::table('modules')->where('identifier', 'sirsoft-ecommerce')->delete();
    }

    /**
     * 시험이 만든 설정 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::delete($this->path);

        parent::tearDown();
    }

    /**
     * 굳은 계산값 키 두 개를 지우고 다른 설정은 남기며, 다시 돌려도 바뀌지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=exact, locked=operator_item, settings_state=populated, request=key_absent
     *
     * @effects computed_keys_removed_from_saved_settings
     */
    public function test_removes_frozen_computed_keys_idempotently(): void
    {
        $this->writeSettings([
            'banner_enabled' => false,
            'necessary_storage_locked' => ['cookie' => ['old_session']],
            'necessary_storage_provided' => ['localStorage' => ['frozen_at_install']],
        ]);

        $this->runStep(new RemoveComputedNecessaryStorageKeys);
        $saved = $this->readSettings();
        $this->assertSame(['banner_enabled' => false], $saved);

        $mtime = filemtime($this->path);
        clearstatcache();
        $this->runStep(new RemoveComputedNecessaryStorageKeys);
        $this->assertSame($mtime, filemtime($this->path), '지울 것이 없으면 파일을 쓰지 않는다');
    }

    /**
     * 설정 파일이 없거나 깨졌으면 아무것도 하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=cookie, notation=exact, locked=operator_item, settings_state=unreachable, request=valid_item
     *
     * @effects computed_keys_removed_from_saved_settings
     */
    public function test_skips_when_settings_missing_or_broken(): void
    {
        $this->runStep(new RemoveComputedNecessaryStorageKeys);
        $this->assertFileDoesNotExist($this->path);

        File::ensureDirectoryExists(dirname($this->path));
        File::put($this->path, '{broken');
        $this->runStep(new RemoveComputedNecessaryStorageKeys);
        $this->assertSame('{broken', File::get($this->path));
    }

    /**
     * 이커머스가 설치돼 있지 않거나 1.3.2 이상이면 두 저장소 종류에서 g7_order_* 만 걷어낸다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=wildcard, locked=operator_item, settings_state=populated, request=key_absent
     *
     * @effects ecommerce_order_keys_removed_from_allowlist
     */
    public function test_removes_ecommerce_order_key_keeping_operator_items(): void
    {
        $this->writeSettings(['necessary_storage_allowlist' => [
            'localStorage' => ['g7_locale', 'g7_order_*', 'my_operator_key'],
            'sessionStorage' => ['g7_order_*', 'g7_filters_*'],
            'cookie' => ['laravel_maintenance'],
        ]]);

        $this->runStep(new RemoveEcommerceOrderKeysFromNecessaryAllowlist);

        $allowlist = $this->readSettings()['necessary_storage_allowlist'];
        $this->assertSame(['g7_locale', 'my_operator_key'], $allowlist['localStorage']);
        $this->assertSame(['g7_filters_*'], $allowlist['sessionStorage']);
        $this->assertSame(['laravel_maintenance'], $allowlist['cookie']);
    }

    /**
     * 이커머스가 아직 훅으로 제공하지 않는 옛 버전이면 남긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=sessionStorage, notation=wildcard, locked=operator_item, settings_state=populated, request=key_absent
     *
     * @effects ecommerce_order_keys_removed_from_allowlist
     */
    public function test_keeps_order_key_while_ecommerce_is_older(): void
    {
        DB::table('modules')->insert([
            'identifier' => 'sirsoft-ecommerce', 'vendor' => 'sirsoft', 'name' => json_encode(['ko' => '쇼핑몰']),
            'version' => '1.3.1', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->writeSettings(['necessary_storage_allowlist' => ['localStorage' => ['g7_order_*']]]);

        $this->runStep(new RemoveEcommerceOrderKeysFromNecessaryAllowlist);

        $this->assertSame(['g7_order_*'], $this->readSettings()['necessary_storage_allowlist']['localStorage']);
    }

    /**
     * 출하 카탈로그(신규 설치)에도 g7_order_* 가 없다 — 이커머스가 제공한다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=wildcard, locked=operator_item, settings_state=empty, request=valid_item
     *
     * @effects shipped_catalog_has_no_ecommerce_order_keys
     */
    public function test_shipped_catalog_has_no_ecommerce_order_key(): void
    {
        foreach (Plugin::DEFAULT_NECESSARY_ALLOWLIST_CATALOG as $scope => $items) {
            $this->assertNotContains('g7_order_*', $items, $scope);
        }
    }

    /**
     * 설정 파일을 씁니다.
     *
     * @param  array<string, mixed>  $settings  설정
     * @return void 반환값 없음
     */
    private function writeSettings(array $settings): void
    {
        File::ensureDirectoryExists(dirname($this->path));
        File::put($this->path, json_encode($settings));
    }

    /**
     * 설정 파일을 읽습니다.
     *
     * @return array<string, mixed> 설정
     */
    private function readSettings(): array
    {
        return json_decode(File::get($this->path), true);
    }

    /**
     * 데이터 마이그레이션 하나를 1.1.2 문맥으로 돌립니다.
     *
     * @param  object  $step  데이터 마이그레이션
     * @return void 반환값 없음
     */
    private function runStep(object $step): void
    {
        $step->run((new UpgradeContext('1.1.1', '1.1.2'))->withCurrentStep('1.1.2'));
    }
}
