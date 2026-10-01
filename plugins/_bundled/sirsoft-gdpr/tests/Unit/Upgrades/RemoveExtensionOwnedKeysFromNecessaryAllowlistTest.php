<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use App\Upgrades\Data\Ext\Plugins\SirsoftGdpr\V1_1_0\Migrations\RemoveExtensionOwnedKeysFromNecessaryAllowlist;
use Illuminate\Support\Facades\File;
use Plugins\Sirsoft\Gdpr\Plugin;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 1.1.0 업그레이드 스텝 — 1.0.4 가 시드한 확장 소유 키 정리 검증
 *
 * 1.1.0 부터 확장 소유 키는 각 확장이 계약 훅으로 제공한다. 설정 파일에 남은 1.0.4 사본은 제공
 * 확장이 빠져도 허용된 채로 남으므로 걷어낸다. 반대로 범위를 넓게 지우면 운영자가 추가한 항목이
 * 소리 없이 사라진다. 둘 다 오류를 남기지 않으므로 여기서 잠근다.
 *
 * 검사 축:
 *   - 1.0.4 시드 확장 키를 시드한 저장소 종류에서만 지운다
 *   - 운영자 항목·코어 항목·다른 설정 키는 보존한다
 *   - 지울 항목이 없으면 파일을 건드리지 않는다
 *   - 재실행해도 결과가 같다 (멱등)
 *   - 설정 파일 없음 / JSON 깨짐 / 허용목록 키 부재는 skip
 *   - 출하 카탈로그에도 확장 소유 키가 없다 (신규 설치는 스텝을 타지 않는다)
 *
 * @scenario scope=sessionStorage, notation=exact, locked=operator_item, settings_state=populated, request=key_absent
 *
 * @effects upgrade_removes_extension_owned_keys_seeded_by_1_0_4,
 *   upgrade_preserves_operator_items_when_removing_extension_keys,
 *   shipped_catalog_contains_no_extension_owned_keys
 */
class RemoveExtensionOwnedKeysFromNecessaryAllowlistTest extends PluginTestCase
{
    /** @var array<string, array<int, string>> 1.0.4 가 시드한 확장 소유 키 */
    private const SEEDED = [
        'localStorage' => [
            'g7_cart_key',
            'g7_guest_order_token',
            'g7_guest_order_number',
            'g7_guest_order_expires_at',
            '__sirsoftKginicisMobilePaymentReturnPending',
            'sirsoft-verification_nhnkcp.formStash',
        ],
        'sessionStorage' => [
            'g7:sirsoft-pay_kginicis:pendingClose',
            'g7:sirsoft-pay_nhnkcp:pendingClose',
            'g7:sirsoft-tosspayments:pendingClose',
            'sirsoft-verification_nhnkcp.formStash',
            '__sirsoftKginicisMobilePaymentReturnPending',
        ],
    ];

    /** @var string 설정 파일 절대 경로 */
    private string $path;

    /**
     * 테스트 환경 준비 — 마이그레이션 적재 및 잔여 설정 파일 정리.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 업그레이드 마이그레이션은 오토로드되지 않는다 — 실행 시점과 같은 방식으로 싣는다.
        require_once __DIR__.'/../../../upgrades/data/1.1.0/migrations/04_RemoveExtensionOwnedKeysFromNecessaryAllowlist.php';

        $this->path = ExtensionStoragePath::plugin('sirsoft-gdpr', 'settings').'/setting.json';

        if (File::exists($this->path)) {
            File::delete($this->path);
        }
    }

    /**
     * 테스트가 만든 설정 파일 정리.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (File::exists($this->path)) {
            File::delete($this->path);
        }

        parent::tearDown();
    }

    /**
     * 1.0.4 시드 확장 키를 지우고 코어·운영자 항목은 순서대로 남깁니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=sessionStorage, notation=exact, locked=operator_item, settings_state=populated, request=key_absent
     *
     * @effects upgrade_removes_extension_owned_keys_seeded_by_1_0_4, upgrade_preserves_operator_items_when_removing_extension_keys
     */
    public function test_removes_seeded_extension_keys_and_keeps_the_rest(): void
    {
        $this->writeSettings([
            'banner_enabled' => true,
            'necessary_storage_allowlist' => [
                'localStorage' => ['g7_locale', ...self::SEEDED['localStorage'], 'g7le.*', 'my_site_key'],
                'sessionStorage' => [...self::SEEDED['sessionStorage'], 'g7.identity.redirectStash', 'op_session_*'],
                'cookie' => ['laravel_maintenance'],
            ],
        ]);

        $this->runStep();

        $settings = $this->readSettings();
        $allowlist = $settings['necessary_storage_allowlist'];
        $this->assertSame(['g7_locale', 'g7le.*', 'my_site_key'], $allowlist['localStorage']);
        $this->assertSame(['g7.identity.redirectStash', 'op_session_*'], $allowlist['sessionStorage']);
        $this->assertSame(['laravel_maintenance'], $allowlist['cookie']);
        $this->assertTrue($settings['banner_enabled']);
    }

    /**
     * 시드한 저장소 종류에서만 지웁니다 — 같은 이름이 다른 종류에 있으면 운영자 항목이다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=cookie, notation=exact, locked=operator_item, settings_state=populated, request=key_absent
     *
     * @effects upgrade_preserves_operator_items_when_removing_extension_keys
     */
    public function test_removes_only_from_the_seeded_scope(): void
    {
        $this->writeSettings([
            'necessary_storage_allowlist' => [
                'localStorage' => ['g7:sirsoft-pay_nhnkcp:pendingClose'],
                'sessionStorage' => ['g7_cart_key'],
                'cookie' => ['g7_cart_key'],
            ],
        ]);

        $this->runStep();

        $allowlist = $this->readSettings()['necessary_storage_allowlist'];
        $this->assertSame(['g7:sirsoft-pay_nhnkcp:pendingClose'], $allowlist['localStorage']);
        $this->assertSame(['g7_cart_key'], $allowlist['sessionStorage']);
        $this->assertSame(['g7_cart_key'], $allowlist['cookie']);
    }

    /**
     * 지울 항목이 없으면 파일을 건드리지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=exact, locked=operator_item, settings_state=populated, request=key_absent
     *
     * @effects upgrade_preserves_operator_items_when_removing_extension_keys
     */
    public function test_leaves_file_untouched_when_nothing_to_remove(): void
    {
        $this->writeSettings([
            'necessary_storage_allowlist' => ['localStorage' => ['g7_locale'], 'cookie' => ['laravel_maintenance']],
        ]);
        $before = File::get($this->path);

        $this->runStep();

        $this->assertSame($before, File::get($this->path));
    }

    /**
     * 재실행해도 결과가 같습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=sessionStorage, notation=exact, locked=operator_item, settings_state=populated, request=key_absent
     *
     * @effects upgrade_removes_extension_owned_keys_seeded_by_1_0_4
     */
    public function test_is_idempotent(): void
    {
        $this->writeSettings([
            'necessary_storage_allowlist' => [
                'localStorage' => ['g7_locale', ...self::SEEDED['localStorage']],
                'sessionStorage' => self::SEEDED['sessionStorage'],
            ],
        ]);

        $this->runStep();
        $once = File::get($this->path);
        $this->runStep();

        $this->assertSame($once, File::get($this->path));
        $this->assertSame([], $this->readSettings()['necessary_storage_allowlist']['sessionStorage']);
    }

    /**
     * 설정 파일이 없으면 만들지 않고 skip 합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=exact, locked=operator_item, settings_state=empty, request=key_absent
     *
     * @effects upgrade_preserves_operator_items_when_removing_extension_keys
     */
    public function test_skips_when_settings_file_missing(): void
    {
        $this->runStep();

        $this->assertFalse(File::exists($this->path));
    }

    /**
     * JSON 이 깨져 있으면 덮어쓰지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=exact, locked=operator_item, settings_state=empty, request=key_absent
     *
     * @effects upgrade_preserves_operator_items_when_removing_extension_keys
     */
    public function test_skips_when_settings_json_malformed(): void
    {
        File::ensureDirectoryExists(dirname($this->path));
        File::put($this->path, '{ this is not json');

        $this->runStep();

        $this->assertSame('{ this is not json', File::get($this->path));
    }

    /**
     * 허용목록 키 자체가 없으면 새로 만들지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=exact, locked=operator_item, settings_state=empty, request=key_absent
     *
     * @effects upgrade_preserves_operator_items_when_removing_extension_keys
     */
    public function test_skips_when_allowlist_key_absent(): void
    {
        $this->writeSettings(['banner_enabled' => true]);

        $this->runStep();

        $this->assertArrayNotHasKey('necessary_storage_allowlist', $this->readSettings());
    }

    /**
     * 출하 카탈로그에 확장 소유 키가 없습니다 (신규 설치는 스텝을 타지 않고 카탈로그를 시드한다).
     *
     * @return void 반환값 없음
     *
     * @scenario scope=sessionStorage, notation=exact, locked=operator_item, settings_state=populated, request=key_absent
     *
     * @effects shipped_catalog_contains_no_extension_owned_keys
     */
    public function test_shipped_catalog_contains_no_extension_owned_keys(): void
    {
        $catalog = Plugin::DEFAULT_NECESSARY_ALLOWLIST_CATALOG;

        $this->assertNotEmpty($catalog['localStorage'], '카탈로그가 비면 이 축은 아무것도 재지 못합니다.');

        foreach (self::SEEDED as $scope => $keys) {
            foreach ($keys as $key) {
                $this->assertNotContains($key, $catalog[$scope] ?? [], "{$scope} 카탈로그에 확장 소유 키 {$key} 가 남아 있습니다.");
            }
        }
    }

    /**
     * 마이그레이션을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runStep(): void
    {
        (new RemoveExtensionOwnedKeysFromNecessaryAllowlist)->run(
            new UpgradeContext(
                fromVersion: '1.0.4',
                toVersion: '1.1.0',
                currentStep: '1.1.0',
                logChannel: 'extension-upgrade',
            )
        );
    }

    /**
     * 설정 파일을 씁니다.
     *
     * @param  array<string, mixed>  $settings  설정 내용
     * @return void 반환값 없음
     */
    private function writeSettings(array $settings): void
    {
        File::ensureDirectoryExists(dirname($this->path));
        File::put($this->path, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * 설정 파일을 읽습니다.
     *
     * @return array<string, mixed> 설정 내용
     */
    private function readSettings(): array
    {
        return json_decode(File::get($this->path), true);
    }
}
