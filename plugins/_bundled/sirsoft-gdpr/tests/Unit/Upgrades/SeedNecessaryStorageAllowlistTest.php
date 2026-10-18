<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use App\Upgrades\Data\Ext\Plugins\SirsoftGdpr\V1_0_4\Migrations\SeedNecessaryStorageAllowlist;
use Illuminate\Support\Facades\File;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 1.0.4 업그레이드 스텝 — 필수 저장 항목 허용목록 백필 검증
 *
 * 이 스텝이 잘못되면 기설치본의 설정 파일이 손상되거나 운영자가 편집한 목록이 덮어써진다.
 * 둘 다 오류를 남기지 않고 "설정이 되돌아갔다" 는 증상으로만 나타난다.
 *
 * 검사 축:
 *   - 키가 없으면 카탈로그를 넣는다
 *   - 키가 이미 있으면 값을 건드리지 않는다 (운영자 편집값 보존)
 *   - 재실행해도 결과가 같다 (멱등)
 *   - 설정 파일이 없으면 skip (설치 시 기본값이 시드한다)
 *   - JSON 이 깨져 있으면 skip (덮어쓰지 않는다)
 *   - 다른 설정 키를 건드리지 않는다
 *   - 잠금 항목을 시드하지 않는다 (담기면 API 로 지울 수 있게 된다)
 *
 * @scenario scope=localStorage, notation=exact, locked=operator_item, settings_state=empty, request=key_absent
 *
 * @effects upgrade_seeds_allowlist_when_key_absent, upgrade_preserves_operator_edited_allowlist, upgrade_is_idempotent, upgrade_skips_when_settings_file_missing, upgrade_skips_when_settings_json_malformed, upgrade_does_not_seed_locked_items, allowlist_default_seeded_from_shipped_catalog
 */
class SeedNecessaryStorageAllowlistTest extends PluginTestCase
{
    /**
     * 설정 파일 절대 경로
     */
    private string $path;

    /**
     * 테스트 환경 준비 — 설정 파일 경로 해석 및 잔여 파일 정리.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 업그레이드 마이그레이션은 오토로드되지 않는다 — AbstractUpgradeStep 이 실행 시점에
        // `data/{version}/migrations/*.php` 를 require_once 한다. 테스트도 같은 방식으로 싣는다.
        require_once __DIR__.'/../../../upgrades/data/1.0.4/migrations/02_SeedNecessaryStorageAllowlist.php';

        $this->path = ExtensionStoragePath::plugin('sirsoft-gdpr', 'settings').'/setting.json';

        if (File::exists($this->path)) {
            File::delete($this->path);
        }
    }

    /**
     * 테스트가 만든 설정 파일 정리.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (File::exists($this->path)) {
            File::delete($this->path);
        }

        parent::tearDown();
    }

    public function test_seeds_allowlist_when_key_is_absent(): void
    {
        $this->writeSettings(['banner_enabled' => true]);

        $this->runStep();

        $settings = $this->readSettings();
        $this->assertArrayHasKey('necessary_storage_allowlist', $settings);
        $this->assertSame(
            ['localStorage', 'sessionStorage', 'cookie'],
            array_keys($settings['necessary_storage_allowlist'])
        );
        $this->assertContains('g7_color_scheme', $settings['necessary_storage_allowlist']['localStorage']);
        $this->assertContains('g7_filters_*', $settings['necessary_storage_allowlist']['localStorage']);
        $this->assertContains('laravel_maintenance', $settings['necessary_storage_allowlist']['cookie']);

        // 다른 키는 그대로 남는다.
        $this->assertTrue($settings['banner_enabled']);
    }

    public function test_preserves_operator_edited_allowlist(): void
    {
        $edited = [
            'localStorage' => ['only_this_one'],
            'sessionStorage' => [],
            'cookie' => [],
        ];
        $this->writeSettings(['necessary_storage_allowlist' => $edited]);

        $this->runStep();

        $this->assertSame($edited, $this->readSettings()['necessary_storage_allowlist']);
    }

    public function test_is_idempotent_on_repeated_runs(): void
    {
        $this->writeSettings(['banner_enabled' => true]);

        $this->runStep();
        $first = $this->readSettings();

        $this->runStep();
        $second = $this->readSettings();

        $this->assertSame($first, $second);
    }

    public function test_skips_when_settings_file_missing(): void
    {
        $this->assertFalse(File::exists($this->path));

        $this->runStep();

        $this->assertFalse(File::exists($this->path), '파일이 없으면 새로 만들지 않는다');
    }

    public function test_skips_when_settings_json_is_malformed(): void
    {
        File::ensureDirectoryExists(dirname($this->path));
        File::put($this->path, '{ not json');

        $this->runStep();

        $this->assertSame('{ not json', File::get($this->path), '깨진 파일을 덮어쓰지 않는다');
    }

    public function test_does_not_seed_locked_items(): void
    {
        $this->writeSettings([]);

        $this->runStep();

        $allowlist = $this->readSettings()['necessary_storage_allowlist'];
        $this->assertNotContains('auth_token', $allowlist['localStorage']);
        $this->assertNotContains('XSRF-TOKEN', $allowlist['cookie']);
        $this->assertNotContains('gdpr_session', $allowlist['cookie']);
    }

    /**
     * 업그레이드 스텝을 실행합니다.
     *
     * @return void
     */
    private function runStep(): void
    {
        (new SeedNecessaryStorageAllowlist)->run(
            new UpgradeContext(
                fromVersion: '1.0.3',
                toVersion: '1.0.4',
                currentStep: '1.0.4',
                logChannel: 'extension-upgrade',
            )
        );
    }

    /**
     * 설정 파일을 씁니다.
     *
     * @param  array<string, mixed>  $settings  설정 내용
     * @return void
     */
    private function writeSettings(array $settings): void
    {
        File::ensureDirectoryExists(dirname($this->path));
        File::put($this->path, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * 설정 파일을 읽습니다.
     *
     * @return array<string, mixed>
     */
    private function readSettings(): array
    {
        return json_decode(File::get($this->path), true);
    }
}
